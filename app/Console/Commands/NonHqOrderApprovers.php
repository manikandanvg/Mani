<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Complaint 2026-11-11 (3): "who can approve an order request other than Head Office?"
 * Every dealer whose supplier (branches.source_branch_id) is NOT Head Office, with the
 * dealer that was shown the Approve button, whether the board's buys-from matrix allows
 * that pairing (only then does the dealer still approve — goods only, never cash stock),
 * and any order a non-HQ login already decided.
 *
 *   php artisan branches:non-hq-approvers
 *   php artisan branches:non-hq-approvers --csv=storage/app/non-hq-approvers.csv
 */
class NonHqOrderApprovers extends Command
{
    protected $signature = 'branches:non-hq-approvers {--csv= : Also write the list to this CSV file}';

    protected $description = 'List the dealers whose order requests a non-HQ dealer could approve';

    public function handle(): int
    {
        $hqIds = Branch::where('level', 'hq')->pluck('id')->all();
        $logins = User::whereNotNull('branch_id')->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'distributor'))
            ->orderBy('id')->get()->groupBy('branch_id');
        $login = fn (int $branchId) => $logins->get($branchId)?->first();

        $head = ['Buyer branch #', 'Buyer login', 'Buyer member code', 'Buyer phone', 'Buyer level',
            'Approver branch #', 'Approver branch', 'Approver in-charge', 'Approver login', 'Approver member code', 'Approver level',
            'Board matrix', 'Approver can still approve'];
        $rows = [];
        $valid = 0;
        Branch::with('sourceBranch')->whereNotNull('source_branch_id')->whereNotIn('source_branch_id', $hqIds ?: [0])
            ->orderBy('source_branch_id')->orderBy('id')->get()
            ->each(function (Branch $b) use (&$rows, &$valid, $login) {
                $s = $b->sourceBranch;
                if (! $s) {
                    return;
                }
                $ok = $s->maySupply($b);
                $valid += $ok ? 1 : 0;
                $bl = $login($b->id);
                $sl = $login($s->id);
                $rows[] = [
                    $b->id, $bl?->name ?? $b->incharge, $bl?->member_code, $b->phone, Branch::levelLabel($b->level),
                    $s->id, trim($s->name . ' ' . $s->city), $s->incharge, $sl ? $sl->name . ' <' . $sl->email . '>' : 'NO LOGIN', $sl?->member_code, Branch::levelLabel($s->level),
                    $ok ? 'allowed' : 'NOT allowed',
                    $ok && $sl ? 'goods orders only (cash stock = Head Office)' : 'no — Head Office only',
                ];
            });

        $this->table($head, array_map(fn ($r) => array_map(fn ($v) => \Illuminate\Support\Str::limit((string) $v, 34), $r), $rows));
        $this->info(sprintf('%d dealer(s) have a non-HQ supplier on record · %d pairing(s) allowed by the board matrix · %d not allowed (now Head Office only).',
            count($rows), $valid, count($rows) - $valid));

        // orders a dealer login already approved / rejected
        $decided = BranchOrderRequest::with(['branch', 'lines'])->whereNotNull('approved_by')
            ->whereIn('approved_by', User::whereHas('roles', fn ($q) => $q->where('name', 'distributor'))->select('id'))
            ->orderBy('approved_at')->get();
        if ($decided->isEmpty()) {
            $this->info('No order request was ever approved / rejected by a dealer login.');
        } else {
            $this->warn($decided->count() . ' order request(s) were decided by a dealer login — check these:');
            $this->table(['Order #', 'Buyer', 'Status', 'Total', 'Cash stock?', 'Decided by', 'When'], $decided->map(fn ($o) => [
                $o->request_no, $o->branch?->pickLabel(), $o->status, \App\Support\Money::group((float) $o->grand_total),
                $o->hasCashLine() ? 'YES' : 'no', User::find($o->approved_by)?->email, (string) $o->approved_at,
            ])->all());
        }

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $head);
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info('Written: ' . $path);
        }

        return self::SUCCESS;
    }
}
