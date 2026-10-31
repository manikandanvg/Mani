<?php

namespace App\Console\Commands;

use App\Services\Payout\CutoffService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Monthly cut-off (docs/CUTOFF-PLAN.md) — the legacy ascript/index step.
 *
 *   php artisan payout:cutoff --cutoff=2026-11-30 --dry-run     # totals + log, writes nothing
 *   php artisan payout:cutoff --cutoff=2026-11-30               # GAP rows (pending) + CBC due, run record
 *   php artisan payout:cutoff --cutoff=2026-11-30 --ep-cap --task-factor   # board policy switches (off = legacy-exact)
 */
class PayoutCutoff extends Command
{
    protected $signature = 'payout:cutoff
        {--cutoff= : last day covered, YYYY-MM-DD (default: last day of the previous month)}
        {--dry-run : compute and log, write nothing}
        {--ep-cap : apply the earning-potential cap (legacy had none)}
        {--task-factor : scale by the Monthly Tasks score (legacy had none)}
        {--recompute : recompute BV / ranks first (legacy pays on the ranks as they stand)}
        {--quiet-log : do not echo the per-bond log to the console}';

    protected $description = 'Monthly cut-off: GAP level commissions + CBC coupons due, as pending rows with a run record and a log (legacy Ascript parity)';

    public function handle(CutoffService $cutoff, \App\Services\NetworkService $network): int
    {
        $date = $this->option('cutoff')
            ? Carbon::createFromFormat('Y-m-d', $this->option('cutoff'))->startOfDay()
            : Carbon::now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        if ($this->option('recompute')) {
            $this->info('Recomputing BV / GBV / unpure / ranks first…');
            $network->recomputeAll();
        }

        $this->info('Cut-off ' . $date->toDateString() . ' → pay month ' . CutoffService::periodFor($date)->format('M Y') . ($this->option('dry-run') ? '  [DRY RUN]' : ''));
        $echo = $this->option('quiet-log') ? null : fn (string $l) => $this->line($l);

        $run = $cutoff->run($date, [
            'ep_cap' => (bool) $this->option('ep-cap'),
            'task_factor' => (bool) $this->option('task-factor'),
            'dry_run' => (bool) $this->option('dry-run'),
        ], $echo);

        $this->newLine();
        $this->table(['pay month', 'bonds', 'GAP rows', 'members', 'GAP gross ₹', 'CBC due', 'CBC worth ₹', 'log'], [[
            $run->label(), $run->gap_bonds, $run->gap_rows, $run->gap_members, number_format((float) $run->gap_gross, 2),
            $run->cbc_rows, number_format((float) $run->cbc_worth, 2), 'storage/app/' . $run->log_path,
        ]]);
        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written. Re-run without --dry-run to generate.');
        } else {
            $this->info('Run #' . $run->id . ' generated. Review it under Commissions → Payout Runs, then approve there (cash wallet, net of TDS + service).');
        }

        return self::SUCCESS;
    }
}
