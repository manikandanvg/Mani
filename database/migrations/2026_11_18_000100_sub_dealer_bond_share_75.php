<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-18: the Sub Dealer contract value (and so the Earning Potential on My Status)
 * is 75 % of the bill, not 25 %. A ₹1,00,000 Sub Dealer bill hands over ₹25,000 of gold
 * (allocation_cont, unchanged) and keeps ₹75,000 on the contract — the bond. The 2026-11-04
 * migration had set plans.allocation = 25 for this scheme, which is the GOLD share.
 *
 *  1. plans.allocation → 75 for the Sub Dealer scheme (new bills: bond = bill × 75 %).
 *  2. Every Sub Dealer bond still stored at the 25 % share is restated to 75 % of its bill, in
 *     the same step — the code derives a legacy bond's bill as bond ÷ share, so the plan and
 *     its bonds must never disagree.
 *
 * Nothing else moves: BV / GBV / ranking BV come from bonds.epin_value (the full bill), and the
 * level income (lvlcom) and coupon (cbc_value) are stored on the bond as rupee amounts.
 */
return new class extends Migration
{
    private const OLD = 25.0;

    private const NEW = 75.0;

    public function up(): void
    {
        $this->restate(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->restate(self::NEW, self::OLD);
    }

    private function restate(float $from, float $to): void
    {
        $planIds = DB::table('plans')->where('name', 'like', '%Sub Dealer%')->pluck('id')->all();
        if (! $planIds) {
            return;
        }

        DB::transaction(function () use ($planIds, $from, $to) {
            DB::table('plans')->whereIn('id', $planIds)->update(['allocation' => $to]);

            DB::table('bonds')->whereIn('plan_id', $planIds)->where('value', '>', 0)->orderBy('id')
                ->chunkById(500, function ($bonds) use ($from, $to) {
                    $invoices = DB::table('sales_invoices')
                        ->whereIn('invoice_no', $bonds->pluck('invoice_no')->filter()->all())
                        ->get(['invoice_no', 'cross_total', 'fx_rate'])->keyBy('invoice_no');

                    foreach ($bonds as $b) {
                        $value = (float) $b->value;
                        $inv = $invoices->get($b->invoice_no);
                        $cross = $inv ? round((float) $inv->cross_total * (float) ($inv->fx_rate ?: 1), 2) : 0.0;
                        // The bill before tax: a new-system bill has it on the invoice; an
                        // old-system bond is the stored share scaled back up.
                        $base = (str_starts_with((string) $b->invoice_no, 'INV-') && $cross > 0)
                            ? $cross
                            : round($value * 100 / $from, 2);

                        // Only bonds that really sit at the old share (skip anything already restated).
                        if (abs($value - round($base * $from / 100, 2)) > 1.0) {
                            continue;
                        }
                        $epin = (float) $b->epin_value;
                        if ($epin > 0 && $base > $epin + 1.0) {
                            continue;
                        }

                        DB::table('bonds')->where('id', $b->id)->update(['value' => round($base * $to / 100, 2)]);
                    }
                });
        });
    }
};
