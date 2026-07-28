<?php

namespace App\Services\Qr;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Plan;

/**
 * Price of an RD gold QR (and the fixed RD renewal amount): the plan's configured
 * gold weight (e.g. 100 mg) at the CURRENT live rate, inclusive of the QR product's
 * making %, wastage %, hallmark and GST — mirrors the sales line maths so the QR
 * redeems exactly one such product.
 */
class GoldQrPricing
{
    public function price(Plan $plan, string $country = 'IN'): float
    {
        $grams = (float) $plan->rd_qr_grams;
        if ($grams <= 0) {
            return 0.0;
        }

        $rate = LiveRate::where('country', $country)->latest('effective_at')->first();
        $perGram = (float) ($rate->gold ?? 0);
        abort_if($perGram <= 0, 422, 'No live gold rate configured — set it before RD billing / renewal.');

        $cp = $plan->rd_qr_product_id ? CatalogProduct::find($plan->rd_qr_product_id) : null;

        $amount = round($grams * $perGram, 2);
        $making = round($amount * (float) ($cp->making_charge_pct ?? 0) / 100, 2);
        $wastage = round($amount * (float) ($cp->wastage_charge_pct ?? 0) / 100, 2);
        $hallmark = round((float) ($cp->hallmark_charge ?? 0), 2);
        $taxable = round($amount + $making + $wastage + $hallmark, 2);
        $gst = round($taxable * (float) ($cp->gst_pct ?? 3) / 100, 2);

        return round($taxable + $gst, 2);
    }
}
