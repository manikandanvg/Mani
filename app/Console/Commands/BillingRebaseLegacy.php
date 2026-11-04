<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-04 — "bill the legacy way". Bills made on the NEW system before this fix carry a
 * bond of allocation_bv % (20 % on a dealership), IC and level amounts on that share, and a coupon
 * of share × cbc % ÷ months. Legacy (and the code from today) uses: bond = bill × plan.allocation,
 * IC / level / CBC = % of the FULL bill, coupon = bill × cbc % every month.
 *
 * This re-bases every new-system bond (invoice_no 'INV-…'; legacy imports carry the numeric
 * estid) and its still-PENDING rows: bond value / lvlcom / cbc_value, pending IC ledger rows,
 * pending coupons (first one prorated to the 1st as at billing), and the buyer's / uplines'
 * BV. Paid rows are never touched — they are listed for a manual decision.
 *
 *   php artisan billing:rebase-legacy            dry run (prints the changes)
 *   php artisan billing:rebase-legacy --apply    writes them, one transaction per bond
 */
class BillingRebaseLegacy extends Command
{
    protected $signature = 'billing:rebase-legacy {--apply : write the changes (default = dry run)} {--bond=* : only these bond ids}';

    protected $description = 'Re-base bills made on the new system to the legacy commission base (bond = bill × allocation; IC/level/CBC on the full bill)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = array_filter(array_map('intval', (array) $this->option('bond')));

        $bonds = Bond::with('plan')
            ->where('invoice_no', 'like', 'INV-%')
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        $this->info(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $bonds->count() . ' new-system bond(s) found');
        $changed = 0;
        $paidWarnings = [];
        $rows = [];

        foreach ($bonds as $bond) {
            $plan = $bond->plan;
            $invoice = SalesInvoice::where('invoice_no', $bond->invoice_no)->first();
            if (! $plan || ! $invoice) {
                $this->warn("  bond {$bond->id}: no plan/invoice — skipped");
                continue;
            }
            $fx = (float) ($invoice->fx_rate ?: 1);
            $base = round((float) $invoice->cross_total * $fx, 2);          // legacy billingvalue, INR
            $newValue = $plan->bondValueFor($base);
            $oldValue = (float) $bond->value;
            $newLvl = array_map(fn ($pct) => round($base * (float) $pct / 100, 2), (array) ($plan->level_schedule ?? []));
            $newCbc = $plan->cbc_count > 0 ? round($base * (float) $plan->cbc_value / 100, 2) : 0.0;
            $oldCbc = (float) $bond->cbc_value;
            $schedule = (array) ($plan->ic_schedule ?? []);

            $ic = CommissionLedger::where('bond_id', $bond->id)->where('type', 'IC')->orderBy('level')->get();
            $icPending = $ic->where('status', 'pending');
            $icPaid = $ic->where('status', 'paid');
            $coupons = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->get();
            $couponsPending = $coupons->where('status', 'pending');
            $couponsPaid = $coupons->where('status', 'paid');
            if ($icPaid->count() || $couponsPaid->count()) {
                $paidWarnings[] = sprintf('bond %d (%s %s): %d paid IC row(s) ₹%s and %d paid coupon(s) ₹%s left as they are',
                    $bond->id, $plan->code, $bond->invoice_no, $icPaid->count(), number_format((float) $icPaid->sum('amount'), 2),
                    $couponsPaid->count(), number_format((float) $couponsPaid->sum('worth'), 2));
            }

            $noChange = abs($newValue - $oldValue) < 0.005 && abs($newCbc - $oldCbc) < 0.005
                && $newLvl === array_map('floatval', (array) ($bond->lvlcom ?? []));
            $rows[] = [
                $bond->id, $plan->code, $bond->invoice_no, number_format($base, 2),
                number_format($oldValue, 2) . ' → ' . number_format($newValue, 2),
                number_format($oldCbc, 2) . ' → ' . number_format($newCbc, 2),
                $icPending->count() . ' pending / ' . $icPaid->count() . ' paid',
                $couponsPending->count() . ' pending / ' . $couponsPaid->count() . ' paid',
                $noChange ? 'same' : 'rebase',
            ];
            if ($noChange || ! $apply) {
                $changed += $noChange ? 0 : 1;
                continue;
            }

            DB::transaction(function () use ($bond, $plan, $base, $newValue, $oldValue, $newLvl, $newCbc, $schedule, $icPending, $couponsPending) {
                // old rank contribution (allocation_bv-scaled) → new (rank % of the bill)
                $oldAlloc = (float) $plan->allocation_bv;
                $oldOriginal = ($oldAlloc > 0 && $oldAlloc < 100) ? $oldValue * 100 / $oldAlloc : $oldValue;
                $oldUnpure = round($oldOriginal * $plan->rankFactor(), 2);
                $newUnpure = round($base * $plan->rankFactor(), 2);

                $bond->update(['value' => $newValue, 'lvlcom' => $newLvl, 'cbc_value' => $newCbc]);

                foreach ($icPending as $row) {
                    $pct = (float) ($schedule[(int) $row->level - 1] ?? 0);
                    $row->update(['amount' => round($base * $pct / 100, 2)]);
                }

                // coupons: the earliest pending one may be the prorated first coupon
                $first = true;
                $billDate = Carbon::parse((string) $bond->bond_date);
                $firstDue = $billDate->copy()->addMonthNoOverflow()->startOfMonth();
                foreach ($couponsPending as $c) {
                    $worth = $newCbc;
                    if ($first && Carbon::parse((string) $c->cbc_date)->isSameDay($firstDue)) {
                        $days = min(30, $billDate->diffInDays($firstDue));
                        $worth = round($newCbc / 30 * $days, 2);
                    }
                    $first = false;
                    $c->update(['worth' => $worth]);
                }

                // BV: the buyer and every upline were credited with the OLD bond value; legacy credits the bill
                $member = Member::find($bond->member_id);
                if ($member) {
                    $member->increment('bv', round($base - $oldValue, 2));
                    $member->increment('unpure_bv', round($newUnpure - $oldUnpure, 2));
                    $seen = [$member->id => true];
                    $ancestor = $member->upline;
                    while ($ancestor && ! isset($seen[$ancestor->id])) {
                        $seen[$ancestor->id] = true;
                        $ancestor->increment('gbv', round($base - $oldValue, 2));
                        $ancestor->increment('unpure_gbv', round($newUnpure - $oldUnpure, 2));
                        $ancestor = $ancestor->upline;
                    }
                }
            });
            $changed++;
        }

        $this->table(['Bond', 'Plan', 'Invoice', 'Bill (INR)', 'Bond value', 'CBC / month', 'IC rows', 'Coupons', 'Action'], $rows);
        foreach ($paidWarnings as $w) {
            $this->warn('  ' . $w);
        }
        $this->info(($apply ? 'Re-based ' : 'Would re-base ') . $changed . ' bond(s).' . ($apply ? '' : ' Run with --apply to write.'));
        if ($apply && $changed > 0) {
            $this->line('Ranks: run `php artisan ranks:recompute` so rank depth follows the new BV.');
        }

        return self::SUCCESS;
    }
}
