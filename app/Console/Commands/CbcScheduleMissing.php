<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\Plan;
use App\Models\SalesInvoice;
use App\Services\Activity\ActivityLogger;
use App\Services\CommissionApprovalService;
use App\Services\SalesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give already-billed bonds the Promotional Incentive (CBC) schedule their plan now carries.
 *
 * User 2026-11-05: the board's Sub Dealer scheme pays 10 % × 24 months, but the plan was
 * configured 0 / 0 (as in legacy), so its bonds were billed without a single coupon. After the
 * plan is corrected this schedules them exactly as billing would have: coupon = bill × cbc %
 * every month, the first one on the 1st after the bill date, prorated by days ÷ 30.
 *
 * Only ACTIVE bonds of the named plan(s) that have NO coupon at all are touched, so a second
 * run changes nothing. Coupons whose month has already come show up at once on Commission
 * Approval (type CBC) — nothing is paid here.
 *
 *   php artisan cbc:schedule-missing --plan=P213            dry run
 *   php artisan cbc:schedule-missing --plan=P213 --apply    writes, one transaction per bond
 */
class CbcScheduleMissing extends Command
{
    protected $signature = 'cbc:schedule-missing {--plan=* : scheme code(s), e.g. P213} {--apply : write the coupons (default = dry run)}';

    protected $description = 'Schedule the monthly CBC coupons for bonds billed before their plan carried a CBC (Sub Dealer 10 % × 24)';

    public function handle(SalesService $sales): int
    {
        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) $this->option('plan'))));
        if (! $codes) {
            $this->error('Name the scheme(s): --plan=P213');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $today = now()->toDateString();

        foreach ($codes as $code) {
            $plan = Plan::where('code', $code)->first();
            if (! $plan) {
                $this->error("Scheme {$code} not found.");

                return self::INVALID;
            }
            $pct = (float) $plan->cbc_value;
            $count = (int) $plan->cbc_count;
            if ($pct <= 0 || $count <= 0) {
                $this->error("Scheme {$code} has no CBC configured (% {$pct}, months {$count}) — set it on Master → Plans first.");

                return self::INVALID;
            }

            $bonds = Bond::with('member')
                ->where('plan_id', $plan->id)
                ->where('status', 'active')
                ->whereNotIn('id', CbcEntry::query()->select('bond_id')->whereNotNull('bond_id'))
                ->orderBy('id')
                ->get();

            $this->info(sprintf('%s — %s: CBC %s %% × %d months; %d active bond(s) without any coupon',
                $apply ? 'APPLY' : 'DRY RUN', $code, rtrim(rtrim(number_format($pct, 3), '0'), '.'), $count, $bonds->count()));

            $bills = 0.0;
            $monthlyTotal = 0.0;
            $coupons = 0;
            $dueNow = [];          // 'Y-m' => worth of coupons whose month has already come
            $skipped = 0;
            $wasLogging = ActivityLogger::$enabled;
            ActivityLogger::$enabled = false;   // one summary row instead of 24 per bond

            try {
                foreach ($bonds as $bond) {
                    if (! $bond->member) {
                        $skipped++;
                        $this->warn("  bond {$bond->id}: no member — skipped");

                        continue;
                    }
                    $bill = $this->billBase($bond, $plan);
                    $monthly = round($bill * $pct / 100, 2);
                    if ($monthly <= 0) {
                        $skipped++;

                        continue;
                    }
                    $bills += $bill;
                    $monthlyTotal += $monthly;
                    $coupons += $count;

                    // the same dates/worths scheduleCbc() writes — for the "already due" preview
                    $start = \Illuminate\Support\Carbon::parse($bond->bond_date);
                    $firstDue = $start->copy()->addMonthNoOverflow()->startOfMonth();
                    for ($i = 0; $i < $count; $i++) {
                        $due = $firstDue->copy()->addMonthsNoOverflow($i);
                        if ($due->toDateString() > $today) {
                            break;
                        }
                        $worth = $i === 0 ? round($monthly / 30 * min(30, $start->diffInDays($firstDue)), 2) : $monthly;
                        $dueNow[$due->format('Y-m')] = ($dueNow[$due->format('Y-m')] ?? 0) + $worth;
                    }

                    if ($apply) {
                        DB::transaction(function () use ($sales, $bond, $monthly, $count) {
                            $sales->scheduleCbc($bond, $bond->member, $monthly, $count, \Illuminate\Support\Carbon::parse($bond->bond_date)->toDateString());
                            // every coupon is pre-scheduled (as the legacy import carries them) — the
                            // monthly cut-off has nothing left to issue for this bond
                            $bond->update(['cbc_value' => $monthly, 'cbc_count' => $count, 'cbc_issued' => $count]);
                        });
                    }
                }
            } finally {
                ActivityLogger::$enabled = $wasLogging;
            }

            $n = $bonds->count() - $skipped;
            $this->line(sprintf('  %d bond(s), bills ₹%s → coupon ₹%s every month, %d coupons %s',
                $n, number_format($bills, 2), number_format($monthlyTotal, 2), $coupons, $apply ? 'scheduled' : 'to schedule'));
            ksort($dueNow);
            $dueTotal = 0.0;
            foreach ($dueNow as $month => $worth) {
                $dueTotal += $worth;
                $this->line(sprintf('  already due %s: ₹%s', \Illuminate\Support\Carbon::parse($month . '-01')->format('M Y'), number_format($worth, 2)));
            }
            if ($dueTotal > 0) {
                $s = CommissionApprovalService::cbcSplit($dueTotal, (bool) $plan->cbc_dealer_rule);
                $this->line(sprintf('  on approval (%s rule): cash wallet ₹%s · TDS ₹%s · service ₹%s · retained by HQ ₹%s',
                    $plan->cbc_dealer_rule ? 'dealership' : 'member', number_format($s['cash'], 2), number_format($s['tds'], 2), number_format($s['service'], 2), number_format($s['coupon'], 2)));
            }

            if ($apply && $n > 0) {
                ActivityLogger::log('commission', 'cbc_scheduled',
                    "CBC schedule added for {$n} {$code} bond(s): ₹" . \App\Support\Money::group($monthlyTotal) . " a month × {$count}",
                    ['details' => ['plan' => $code, 'bonds' => $n, 'monthly' => round($monthlyTotal, 2), 'coupons' => $coupons, 'due_now' => round($dueTotal, 2)]]);
            }
        }

        if (! $apply) {
            $this->comment('Dry run — nothing written. Add --apply to schedule the coupons.');
        } else {
            $this->comment('Next: Commissions → Commission Approval → type CBC → Scheme filter, or commission:approve --type=CBC --plan=…');
        }

        return self::SUCCESS;
    }

    /**
     * The bill the coupon is a % of (legacy billingvalue, INR, before tax): a new-system bill
     * has its invoice; a legacy bond is bond value ÷ the plan's bond share (25,000 ÷ 25 % = 1,00,000).
     */
    protected function billBase(Bond $bond, Plan $plan): float
    {
        if (str_starts_with((string) $bond->invoice_no, 'INV-')
            && ($inv = SalesInvoice::where('invoice_no', $bond->invoice_no)->first())) {
            return round((float) $inv->cross_total * (float) ($inv->fx_rate ?: 1), 2);
        }

        return $plan->billValueFromBond((float) $bond->value);
    }
}
