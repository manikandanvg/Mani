<?php

namespace App\Services\Qr;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Services\Charges\ChargeResolver;

/**
 * Price of an RD gold QR (and the fixed RD renewal amount): the plan's configured
 * gold weight (e.g. 100 mg) at the CURRENT live rate, plus making + wastage from the
 * Charge Brackets slab for that weight, plus GST — the legacy rdsales / Lscript maths:
 *
 *   100 mg @ ₹12,730/g = ₹1,273.00 → + 20 % making + 20 % wastage = ₹1,782.20 → + 3 % GST = ₹1,835.67
 *
 * User 2026-11-05: the charges were read from the plan's optional "QR product" only, which
 * is not set on the Gold Saving plans — so the sale QR and every renewal carried the 3 % GST
 * alone (₹1,311.19). The Charge Brackets table is the source now; the QR product's own
 * percentages are used only when no slab exists for gold at all.
 */
class GoldQrPricing
{
    public function price(Plan $plan, string $country = 'IN', ?float $goldRate = null): float
    {
        return $this->breakup($plan, $country, $goldRate)['total'];
    }

    /**
     * The price with its parts, for the renewal screen and repairs.
     *
     * @param  float|null  $goldRate  price at this per-gram rate instead of the live one (repairs)
     * @return array{grams: float, rate: float, metal: float, making_pct: float, making: float, wastage_pct: float, wastage: float, hallmark: float, taxable: float, gst_pct: float, gst: float, total: float}
     */
    public function breakup(Plan $plan, string $country = 'IN', ?float $goldRate = null): array
    {
        $grams = (float) $plan->rd_qr_grams;
        $zero = ['grams' => $grams, 'rate' => 0.0, 'metal' => 0.0, 'making_pct' => 0.0, 'making' => 0.0, 'wastage_pct' => 0.0,
            'wastage' => 0.0, 'hallmark' => 0.0, 'taxable' => 0.0, 'gst_pct' => 0.0, 'gst' => 0.0, 'total' => 0.0];
        if ($grams <= 0) {
            return $zero;
        }

        $perGram = $goldRate ?? (float) (LiveRate::where('country', $country)->latest('effective_at')->value('gold') ?? 0);
        abort_if($perGram <= 0, 422, 'No live gold rate configured — set it before Gold Saving billing / renewal.');

        $cp = $plan->rd_qr_product_id ? CatalogProduct::find($plan->rd_qr_product_id) : null;
        $slab = app(ChargeResolver::class)->forWeight('gold', $grams);
        $makingPct = $slab['bracket_id'] ? $slab['making_pct'] : (float) ($cp->making_charge_pct ?? 0);
        $wastagePct = $slab['bracket_id'] ? $slab['wastage_pct'] : (float) ($cp->wastage_charge_pct ?? 0);
        $gstPct = (float) ($cp->gst_pct ?? 3);

        $amount = round($grams * $perGram, 2);
        $making = round($amount * $makingPct / 100, 2);
        $wastage = round($amount * $wastagePct / 100, 2);
        $hallmark = round((float) ($cp->hallmark_charge ?? 0), 2);
        $taxable = round($amount + $making + $wastage + $hallmark, 2);
        $gst = round($taxable * $gstPct / 100, 2);

        return ['grams' => $grams, 'rate' => $perGram, 'metal' => $amount, 'making_pct' => $makingPct, 'making' => $making,
            'wastage_pct' => $wastagePct, 'wastage' => $wastage, 'hallmark' => $hallmark, 'taxable' => $taxable,
            'gst_pct' => $gstPct, 'gst' => $gst, 'total' => round($taxable + $gst, 2)];
    }
}
