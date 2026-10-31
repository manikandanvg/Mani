<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Parity check (docs/CUTOFF-PLAN.md §4): the new cut-off of a pay month against the legacy
 * run of the same month, read from the `legacy` connection (the legacy DB AFTER its
 * Ascript run). Compares per member: GAP rows + gross (tbl_memberearning_level vs
 * commission_ledger), the GAP log net (tbl_gap_log), and the CBC coupons due (tbl_cbc).
 *
 *   php artisan payout:compare --period=2026-11-01 [--export=C:\path\diff.xlsx] [--limit=40]
 */
class PayoutCompare extends Command
{
    protected $signature = 'payout:compare {--period= : pay month 1st, YYYY-MM-01 (legacy `doe` / `date`)} {--export= : write the full diff as xlsx} {--limit=40 : mismatches shown on screen}';

    protected $description = 'Compare a new-system cut-off with the legacy run of the same month (per member, GAP + CBC)';

    public function handle(): int
    {
        $period = $this->option('period')
            ? Carbon::createFromFormat('Y-m-d', $this->option('period'))->startOfMonth()
            : Carbon::now()->startOfMonth();
        $p = $period->toDateString();
        $end = $period->copy()->endOfMonth()->toDateString();
        $L = DB::connection('legacy');
        $this->info('Pay month ' . $period->format('M Y') . ' — legacy ' . config('database.connections.legacy.database') . ' vs new ' . config('database.connections.mysql.database'));

        // ---- GAP register per member
        $legacy = [];
        foreach ($L->table('tbl_memberearning_level')->where('doe', $p)->whereRaw("UPPER(comname) = 'GAP'")
            ->selectRaw('UPPER(TRIM(memberuserid)) code, COUNT(*) n, SUM(earnamount) gross')->groupBy('code')->get() as $r) {
            $legacy[$r->code] = ['n' => (int) $r->n, 'gross' => round((float) $r->gross, 2)];
        }
        $new = [];
        foreach (DB::table('commission_ledger')->join('members', 'members.id', '=', 'commission_ledger.member_id')
            ->where('type', 'GAP')->whereBetween('earned_on', [$p, $end])
            ->selectRaw('UPPER(TRIM(members.member_code)) code, COUNT(*) n, SUM(amount) gross')->groupBy('code')->get() as $r) {
            $new[$r->code] = ['n' => (int) $r->n, 'gross' => round((float) $r->gross, 2)];
        }
        $diff = [];
        foreach (array_unique(array_merge(array_keys($legacy), array_keys($new))) as $code) {
            $a = $legacy[$code] ?? ['n' => 0, 'gross' => 0.0];
            $b = $new[$code] ?? ['n' => 0, 'gross' => 0.0];
            if ($a['n'] !== $b['n'] || abs($a['gross'] - $b['gross']) > 0.01) {
                $diff[] = [$code, $a['n'], $a['gross'], $b['n'], $b['gross'], round($b['gross'] - $a['gross'], 2)];
            }
        }
        $lt = ['members' => count($legacy), 'rows' => array_sum(array_column($legacy, 'n')), 'gross' => array_sum(array_column($legacy, 'gross'))];
        $nt = ['members' => count($new), 'rows' => array_sum(array_column($new, 'n')), 'gross' => array_sum(array_column($new, 'gross'))];
        $this->table(['GAP register', 'members', 'rows', 'gross ₹'], [
            ['legacy tbl_memberearning_level', $lt['members'], $lt['rows'], number_format($lt['gross'], 2)],
            ['new commission_ledger', $nt['members'], $nt['rows'], number_format($nt['gross'], 2)],
        ]);
        usort($diff, fn ($x, $y) => abs($y[5]) <=> abs($x[5]));
        if ($diff) {
            $this->warn(count($diff) . ' member(s) differ (largest first):');
            $this->table(['member', 'legacy rows', 'legacy gross', 'new rows', 'new gross', 'delta'], array_slice($diff, 0, (int) $this->option('limit')));
        } else {
            $this->info('GAP register: 100 % match (' . count($new) . ' members).');
        }

        // ---- GAP log (legacy nettotal = gross per member; grandtotal = net after 5 + 5)
        $gl = $L->table('tbl_gap_log')->where('date', $p)->selectRaw('COUNT(*) n, SUM(nettotal) gross, SUM(tds) tds, SUM(servicetax) sc, SUM(grandtotal) net')->first();
        $this->table(['GAP log (legacy tbl_gap_log)', 'members', 'gross ₹', 'TDS ₹', 'service ₹', 'net ₹'], [[
            $period->format('M Y'), (int) ($gl->n ?? 0), number_format((float) ($gl->gross ?? 0), 2), number_format((float) ($gl->tds ?? 0), 2), number_format((float) ($gl->sc ?? 0), 2), number_format((float) ($gl->net ?? 0), 2),
        ]]);
        $run = \App\Models\PayoutRun::whereDate('period', $p)->where('status', '!=', 'dry_run')->latest('id')->first();
        if ($run) {
            $s = $run->memberSummary();
            $this->table(['GAP log (new run #' . $run->id . ')', 'members', 'gross ₹', 'TDS ₹', 'service ₹', 'net ₹'], [[
                $run->label(), count($s), number_format(array_sum(array_column($s, 'gross')), 2), number_format(array_sum(array_column($s, 'tds')), 2),
                number_format(array_sum(array_column($s, 'service')), 2), number_format(array_sum(array_column($s, 'net')), 2),
            ]]);
        } else {
            $this->warn('No new-system run for this month yet (payout:cutoff).');
        }

        // ---- CBC due in the month
        $lc = $L->table('tbl_cbc')->whereBetween('cbcdate', [$p, $end])->selectRaw('COUNT(*) n, SUM(cbworth) worth, SUM(cbcavailabilty = \'PASSED\') passed')->first();
        $nc = DB::table('cbc_entries')->whereBetween('cbc_date', [$p, $end])->selectRaw("COUNT(*) n, SUM(worth) worth, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) paid")->first();
        $this->table(['CBC due ' . $period->format('M Y'), 'coupons', 'worth ₹', 'passed / paid'], [
            ['legacy tbl_cbc', (int) ($lc->n ?? 0), number_format((float) ($lc->worth ?? 0), 2), (int) ($lc->passed ?? 0)],
            ['new cbc_entries', (int) ($nc->n ?? 0), number_format((float) ($nc->worth ?? 0), 2), (int) ($nc->paid ?? 0)],
        ]);

        if ($this->option('export')) {
            @mkdir(dirname($this->option('export')), 0775, true);
            $writer = new XlsxWriter;
            $writer->openToFile($this->option('export'));
            $writer->addRow(Row::fromValues(['Member', 'Legacy rows', 'Legacy gross', 'New rows', 'New gross', 'Delta']));
            foreach ($diff as $d) {
                $writer->addRow(Row::fromValues($d));
            }
            $writer->close();
            $this->info('Diff written to ' . $this->option('export'));
        }

        return $diff ? self::FAILURE : self::SUCCESS;
    }
}
