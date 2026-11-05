<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\Network\PlacementCorrectionService;
use Illuminate\Console\Command;

/**
 * Report + correct wrong Senior Distributor (placement) IDs keyed on the Sales screen
 * (user 2026-11-05). Dry run unless --apply.
 *
 *   php artisan members:fix-placement                         list everyone since 2026-11-02 whose senior ≠ referrer
 *   php artisan members:fix-placement --csv=placement.csv     same list as a file
 *   php artisan members:fix-placement --member=LJW1234        preview moving one under their referrer
 *   php artisan members:fix-placement --member=LJW1234 --to=LJW0999 --apply
 *   php artisan members:fix-placement --all --apply           move every listed one under their referrer
 *
 * Then: php artisan ranks:recompute (BV / GBV / ranks follow the new tree).
 */
class MembersFixPlacement extends Command
{
    protected $signature = 'members:fix-placement
        {--since=2026-11-02 : list members joined on/after this date}
        {--member=* : distributor code(s) to move}
        {--to= : new Senior Distributor code (default: each member\'s Referred Distributor)}
        {--all : move every listed mismatch under its referrer}
        {--reissue-paid : also issue IC to the right upline where the wrong one was already approved/paid}
        {--csv= : write the mismatch list to this file}
        {--apply : write the changes}';

    protected $description = 'Correct Senior Distributor (placement) IDs and move the frozen upline + IC with them';

    public function handle(PlacementCorrectionService $svc): int
    {
        $codes = array_filter(array_map('trim', (array) $this->option('member')));
        $to = $this->option('to') ? Member::where('member_code', trim($this->option('to')))->first() : null;
        if ($this->option('to') && ! $to) {
            $this->error('Unknown --to code ' . $this->option('to'));

            return self::FAILURE;
        }
        if ($to && count($codes) !== 1) {
            $this->error('--to needs exactly one --member.');

            return self::FAILURE;
        }

        if (! $codes && ! $this->option('all')) {
            return $this->listMismatches($svc);
        }

        $members = $codes
            ? Member::whereIn('member_code', $codes)->get()
            : $svc->mismatches((string) $this->option('since'));
        if ($codes && $members->count() !== count($codes)) {
            $this->error('Unknown member code(s): ' . implode(', ', array_diff($codes, $members->pluck('member_code')->all())));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        $paidWrong = [];
        $totals = ['removed' => 0.0, 'issued' => 0.0];
        foreach ($members as $m) {
            $target = $to ?? $m->referrer;
            if (! $target) {
                $rows[] = [$m->member_code, $m->upline?->member_code, '—', '', '', '', 'no referrer — give --to'];
                continue;
            }
            // already under the right senior → still checks the IC (e.g. a --reissue-paid re-run)
            $already = (int) $m->upline_id === (int) $target->id;
            $r = $svc->move($m, $target, $apply, (bool) $this->option('reissue-paid'));
            $changed = $r['bonds'] || $r['ic_removed'] || $r['ic_issued'];
            $rows[] = [$r['member'], $r['from'], $r['to'], $r['bonds'],
                $r['ic_removed'] . ' / ₹' . number_format($r['ic_removed_amount'], 2),
                $r['ic_issued'] . ' / ₹' . number_format($r['ic_issued_amount'], 2),
                $r['error'] ?? (($already && ! $changed) ? 'already right' : ($apply ? 'MOVED' : 'dry run'))];
            $paidWrong = array_merge($paidWrong, $r['paid_wrong']);
            if (! $r['error']) {
                $totals['removed'] += $r['ic_removed_amount'];
                $totals['issued'] += $r['ic_issued_amount'];
            }
        }

        $this->table(['Distributor', 'Senior was', 'Senior now', 'Bonds', 'Pending IC taken off wrong upline', 'IC issued to right upline (pending)', 'Result'], $rows);
        $this->line(sprintf('Pending IC taken off wrong uplines ₹%s · issued to the right uplines ₹%s (pending — approve under Commission Approval)',
            number_format($totals['removed'], 2), number_format($totals['issued'], 2)));

        if ($paidWrong) {
            $this->warn(count($paidWrong) . ' IC row(s) were ALREADY approved/paid to the wrong upline — not touched'
                . ($this->option('reissue-paid') ? '; the right upline was issued theirs anyway.' : '; re-run with --reissue-paid to also issue them to the right upline.'));
            $this->table(['Invoice', 'Buyer', 'Level', 'Paid to', 'Should be', 'Amount', 'Status'],
                array_map(fn ($p) => [$p['invoice'], $p['buyer'], $p['level'], $p['paid_to'], $p['should_be'], number_format($p['amount'], 2), $p['status']], $paidWrong));
        }

        $this->line($apply ? 'Done. Now run: php artisan ranks:recompute' : 'Dry run — nothing written. Add --apply.');

        return self::SUCCESS;
    }

    protected function listMismatches(PlacementCorrectionService $svc): int
    {
        $list = $svc->mismatches((string) $this->option('since'));
        $rows = $list->map(fn (Member $m) => [
            $m->member_code, $m->name, (string) $m->joined_on?->format('d-m-Y'), $m->branch?->name,
            $m->referrer?->member_code, $m->upline?->member_code, Member::where('upline_id', $m->id)->count(),
        ])->all();
        $head = ['Distributor', 'Name', 'Joined', 'Branch', 'Referred by', 'Senior (placement)', 'Direct downlines'];

        $this->table($head, $rows);
        $this->info($list->count() . ' distributor(s) since ' . $this->option('since') . ' whose senior is not their referrer.');

        if ($file = $this->option('csv')) {
            $fh = fopen($file, 'w');
            fputcsv($fh, $head);
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info("Written {$file}");
        }
        $this->line('Some of these may be intended (a different placement on purpose) — move them one by one with --member, or all with --all.');

        return self::SUCCESS;
    }
}
