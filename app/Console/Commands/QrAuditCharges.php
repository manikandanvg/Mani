<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\SalesInvoice;
use App\Services\Charges\ChargeResolver;
use App\Services\Qr\GoldQrPricing;
use Illuminate\Console\Command;

/**
 * User 2026-11-05 — bills and renewals made on the NEW system before the Charge Brackets fix:
 *
 *  A. Gold-purchase QRs (dealerships, Sub Dealer, G5, G24 …): the gram figure was worth ÷ rate
 *     (₹25,000 → 1.9639 g) instead of the legacy cash→gold conversion (GST and the slab's
 *     making + wastage out → 1.8159 g). The rupee worth was right, so nothing was over-paid —
 *     only the promised grams are overstated. --apply corrects the grams of the PENDING ones.
 *
 *  B. Gold Saving 100 mg QRs and renewals (PLUS1 / PLUS2): priced at gold + 3 % GST only
 *     (₹1,311.19) instead of gold + making + wastage + GST (₹1,835.67). Money was collected
 *     short, so these are only LISTED — what to do with each is Head Office's decision.
 *     Only records made since the new system went live (--since, default 2026-11-01) are looked
 *     at: older ones came from legacy, which priced them correctly at that day's gold rate.
 *
 *   php artisan qr:audit-charges            list both
 *   php artisan qr:audit-charges --since=2026-11-01
 *   php artisan qr:audit-charges --apply    also correct the grams in A (never touches money)
 */
class QrAuditCharges extends Command
{
    /** B: "right if the charges had been added" — within this % of today's bracket price. */
    public const TOLERANCE_PCT = 6.0;

    protected $signature = 'qr:audit-charges {--apply : correct the overstated grams of pending gold-purchase QRs (A); B is never changed}
        {--since=2026-11-01 : B looks only at QRs / renewals made on or after this date (new-system go-live)}';

    protected $description = 'List QRs / Gold Saving renewals priced without Charge Brackets making + wastage; --apply fixes the gram figure only';

    public function handle(ChargeResolver $charges, GoldQrPricing $pricing): int
    {
        $apply = (bool) $this->option('apply');
        $since = \Illuminate\Support\Carbon::parse((string) $this->option('since'))->toDateString();

        // ---------------------------------------------------------------- A: overstated grams
        $rows = [];
        $fixed = 0;
        $qrs = RedeemableQr::with('bond.plan')
            ->where('status', 'pending')->where('qr_mode', 'gold')
            ->where('invoice_no', 'like', 'INV-%')
            ->whereNotNull('gram_worth')
            ->orderBy('id')->get();
        foreach ($qrs as $qr) {
            $plan = $qr->bond?->plan;
            if (! $plan || (float) $plan->rd_qr_grams > 0) {
                continue;   // fixed-weight QRs are section B
            }
            $rate = (float) (SalesInvoice::where('invoice_no', $qr->invoice_no)->value('gold_rate') ?? 0);
            $worth = (float) $qr->cash_worth;
            if ($rate <= 0 || $worth <= 0 || abs((float) $qr->gram_worth - round($worth / $rate, 4)) > 0.0002) {
                continue;   // not the worth ÷ rate figure — already right, or set by hand
            }
            $grams = $charges->gramsForWorth($worth, $rate);
            $rows[] = [$qr->qr_code, $qr->invoice_no, $plan->code, number_format($worth, 2), number_format($rate, 2),
                number_format((float) $qr->gram_worth, 4), number_format($grams, 4)];
            if ($apply) {
                $qr->update(['gram_worth' => $grams]);
                $fixed++;
            }
        }
        $this->info('A. Gold-purchase QRs with grams = worth ÷ rate (no GST / making / wastage taken out): ' . count($rows));
        if ($rows) {
            $this->table(['QR', 'Invoice', 'Scheme', 'Worth ₹', 'Gold rate', 'Grams now', 'Grams (legacy conversion)'], $rows);
        }
        if ($apply) {
            $this->line("   {$fixed} corrected.");
        }

        // ---------------------------------------------------------------- B: Gold Saving priced short
        $short = [];
        $shortTotal = 0.0;
        foreach (Plan::where('rd_qr_grams', '>', 0)->get() as $plan) {
            try {
                $b = $pricing->breakup($plan);
            } catch (\Throwable) {
                $this->warn("   {$plan->code}: no live gold rate — skipped");

                continue;
            }
            $factor = 1 + ($b['making_pct'] + $b['wastage_pct']) / 100;
            if ($factor <= 1 || $b['total'] <= 0) {
                continue;   // no slab → nothing was missed
            }
            $isShort = fn (float $paid) => $paid > 0 && abs($paid * $factor - $b['total']) / $b['total'] * 100 <= self::TOLERANCE_PCT;

            foreach (RedeemableQr::with('member')->where('qr_mode', 'gold')->whereDate('created_at', '>=', $since)
                ->whereIn('bond_id', \App\Models\Bond::query()->select('id')->where('plan_id', $plan->id))->cursor() as $qr) {
                if ($isShort((float) $qr->cash_worth)) {
                    $due = round((float) $qr->cash_worth * $factor, 2);
                    $short[] = [$plan->code, 'QR ' . $qr->qr_code . ' (' . $qr->status . ')', $qr->member?->member_code, optional($qr->created_at)->format('d M Y'),
                        number_format((float) $qr->cash_worth, 2), number_format($due, 2), number_format($due - (float) $qr->cash_worth, 2)];
                }
            }
            foreach (RdEntry::with('member')->whereDate('paid_on', '>=', $since)
                ->whereIn('bond_id', \App\Models\Bond::query()->select('id')->where('plan_id', $plan->id))->cursor() as $e) {
                if ($isShort((float) $e->value)) {
                    $due = round((float) $e->value * $factor, 2);
                    $shortTotal += $due - (float) $e->value;
                    $short[] = [$plan->code, 'Renewal #' . $e->id . ' (due ' . $e->due_count . ')', $e->member?->member_code, optional($e->paid_on)->format('d M Y'),
                        number_format((float) $e->value, 2), number_format($due, 2), number_format($due - (float) $e->value, 2)];
                }
            }
        }
        $this->info("B. Gold Saving QRs / renewals since {$since} priced with the 3 % GST only (listed, never changed): " . count($short));
        if ($short) {
            $this->table(['Scheme', 'What', 'Member', 'Date', 'Priced ₹', 'With making + wastage ₹', 'Short ₹'], $short);
            $this->line('   Renewals collected short in all: ₹' . number_format($shortTotal, 2));
        }

        if (! $apply && $rows) {
            $this->comment('Add --apply to correct the grams in A.');
        }

        return self::SUCCESS;
    }
}
