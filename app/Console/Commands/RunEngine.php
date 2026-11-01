<?php

namespace App\Console\Commands;

use App\Services\CommissionService;
use App\Services\NetworkService;
use App\Services\SettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The nightly MLM engine — replaces the legacy Ascript/Lscript cron chain.
 * Run order matters: recompute (BV->GBV->unpure->ranks) before GAP payout.
 *
 *   php artisan engine:run                 recompute + settlements (GAP/CBC: see payout:cutoff)
 *   php artisan engine:run --only=recompute
 *   php artisan engine:run --only=gap
 *   php artisan engine:run --only=cbc
 *   php artisan engine:run --period=2026-06
 */
class RunEngine extends Command
{
    protected $signature = 'engine:run {--only= : recompute|gap|cbc|settlement|statements} {--period= : YYYY-MM}';

    protected $description = 'Recompute BV/ranks, generate IC/GAP/CBC commissions and settle matured contracts';

    public function handle(NetworkService $network, CommissionService $commission, SettlementService $settlement): int
    {
        $period = $this->option('period')
            ? Carbon::createFromFormat('Y-m', $this->option('period'))->startOfMonth()
            : Carbon::now();
        $only = $this->option('only');

        if (! $only || $only === 'recompute') {
            $this->info('Recomputing BV / GBV / unpure / ranks...');
            $network->recomputeAll();
            $this->line('  done.');
        }

        // RETIRED from the default chain (go-live 2026-11-01): the monthly GAP + CBC now come
        // from `payout:cutoff` (legacy Ascript parity — stored level amounts, coupons by due
        // date, Payout Runs screen). These two steps price GAP from plan % and mint coupon rows
        // of their own, which would clash with the legacy coupon schedule carried over, so they
        // only run when asked for by name.
        if ($only === 'gap') {
            $this->warn('engine:run --only=gap is retired — the monthly cut-off is `payout:cutoff` (Commissions → Payout Runs).');
            $this->info('Running GAP / level commissions (old plan-% engine)...');
            $n = $commission->runGap($period);
            $this->line("  {$n} GAP rows issued.");
        }

        if ($only === 'cbc') {
            $this->warn('engine:run --only=cbc is retired — the monthly cut-off is `payout:cutoff` (Commissions → Payout Runs).');
            $this->info('Issuing CBC installments (old engine)...');
            $n = $commission->issueCbc($period);
            $this->line("  {$n} CBC rows issued.");
        }

        if (! $only || $only === 'settlement') {
            $this->info('Settling matured contracts...');
            $n = $settlement->run();
            $this->line("  {$n} contracts settled.");
        }

        // Retired payout-statement system: EXPLICIT opt-in only — never part of the full
        // chain, because it flips pending ledger rows to approved behind the manual gate.
        if ($only === 'statements') {
            $this->info('Generating payout statements...');
            $n = $commission->generatePayoutStatements($period->format('Y-m'));
            $this->line("  {$n} statements generated.");
        }

        $this->info('Engine run complete.');

        return self::SUCCESS;
    }
}
