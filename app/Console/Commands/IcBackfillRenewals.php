<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\RdEntry;
use App\Services\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Board complaint 2026-11-25 ("sales incentive not going properly"). Every Gold Saving renewal
 * pays the scheme's Sales Incentive (IC) to the 10 people above — but that rule went in on
 * 4 Nov 2026; renewals entered on 2–4 Nov before it produced NO incentive line at all.
 *
 * This lists every renewal of the new system that has no IC line (ledger invoice_no "RD-<id>")
 * and, with --apply, issues the missing lines exactly as a renewal does today: on the bond's
 * billed value, per level, capped by each upline's earning potential, earned on the day the
 * renewal was paid. The lines are PENDING — nothing reaches a wallet until Commission Approval.
 *
 *   php artisan ic:backfill-renewals            list only
 *   php artisan ic:backfill-renewals --apply    issue the missing lines (safe to run twice)
 */
class IcBackfillRenewals extends Command
{
    protected $signature = 'ic:backfill-renewals {--apply : issue the missing lines (default: list only)}
        {--from=2026-11-01 : renewals entered on or after this day}';

    protected $description = 'Issue the Sales Incentive that Gold Saving renewals of 2–4 Nov 2026 never produced';

    public function handle(CommissionService $commission): int
    {
        $from = Carbon::parse((string) $this->option('from'))->startOfDay();
        $has = CommissionLedger::where('invoice_no', 'like', 'RD-%')->distinct()->pluck('invoice_no')->flip();
        $missing = RdEntry::where('created_at', '>=', $from)->orderBy('id')->get()
            ->reject(fn (RdEntry $e) => isset($has['RD-' . $e->id]))->values();

        if ($missing->isEmpty()) {
            $this->info('Every renewal since ' . $from->toDateString() . ' has its Sales Incentive lines — nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Entered on', 'Renewals without incentive', 'Collected ₹'], $missing
            ->groupBy(fn (RdEntry $e) => $e->created_at->toDateString())
            ->map(fn ($g, $day) => [$day, $g->count(), number_format((float) $g->sum('value'), 2)])->values()->all());
        $this->line($missing->count() . ' renewal(s), ₹' . number_format((float) $missing->sum('value'), 2) . ' collected, no Sales Incentive line.');

        $apply = (bool) $this->option('apply');
        $lines = 0;
        $amount = 0.0;
        $skipped = 0;
        foreach ($missing as $entry) {
            $bond = Bond::with('plan', 'member')->find($entry->bond_id);
            if (! $bond || $bond->status === 'cancelled' || empty($bond->plan?->ic_schedule)) {
                $skipped++;

                continue;
            }
            // inside a transaction either way: a dry run issues the lines to count them, then rolls back
            DB::beginTransaction();
            try {
                $n = $commission->issueInstantCommissionOn($bond, (float) ($bond->epin_value ?: $bond->value),
                    Carbon::parse($entry->paid_on)->toDateString(), 'RD-' . $entry->id, (int) $entry->branch_id ?: null);
                $lines += $n;
                $amount += (float) CommissionLedger::where('invoice_no', 'RD-' . $entry->id)->sum('amount');
                $apply ? DB::commit() : DB::rollBack();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('Renewal #' . $entry->id . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        $this->info(($apply ? 'Issued ' : 'Would issue ') . $lines . ' incentive line(s) worth ₹' . number_format($amount, 2)
            . ' (pending — paid only through Commission Approval).' . ($skipped ? ' Skipped ' . $skipped . ' (contract cancelled / no incentive schedule).' : ''));
        if (! $apply) {
            $this->line('List only — nothing written. Add --apply.');
        }

        return self::SUCCESS;
    }
}
