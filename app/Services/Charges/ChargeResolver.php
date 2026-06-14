<?php

namespace App\Services\Charges;

use App\Models\ChargeBracket;
use App\Models\LiveRate;

/**
 * Weight-bracket making/wastage resolver — the modern replacement for the legacy
 * tbl_othercharges lookup (Lscript: cash→gold reversal). Reads `charge_brackets`
 * (material + wt_from..wt_to → making_pct/wastage_pct) instead of flat mc/wc amounts.
 *
 * Used by the cash→gold reversal/redeem path to compute the net gold a cash amount
 * converts to after deducting making + wastage for the resolved weight slab.
 */
class ChargeResolver
{
    /**
     * Making + wastage percentages for $grams of $material. Brackets are inclusive on
     * both ends (wt_from ≤ g ≤ wt_to); the narrowest matching slab wins. Returns zeros
     * when no slab matches (caller treats "no bracket" as "no charge").
     */
    public function forWeight(string $material, float $grams): array
    {
        $b = ChargeBracket::where('material', $material)
            ->where('wt_from', '<=', $grams)
            ->where('wt_to', '>=', $grams)
            ->orderByRaw('(wt_to - wt_from) asc')   // prefer the tightest matching slab
            ->first();

        return [
            'making_pct' => (float) ($b->making_pct ?? 0),
            'wastage_pct' => (float) ($b->wastage_pct ?? 0),
            'bracket_id' => $b?->id,
        ];
    }

    /**
     * Convert a cash amount (ex-GST) into net redeemable gold grams, deducting making +
     * wastage for the resolved weight slab. The charge is applied to the cash, exactly as
     * the legacy reversal did (charge taken off, then re-divided by the gold rate).
     *
     * @return array{gross_grams: float, net_grams: float, making_pct: float, wastage_pct: float, charge: float, bracket_id: ?int}
     */
    public function cashToGold(float $cashExGst, ?float $goldRatePerGram = null, string $material = 'gold'): array
    {
        $rate = $goldRatePerGram ?? $this->goldRate();
        if ($rate <= 0 || $cashExGst <= 0) {
            return ['gross_grams' => 0.0, 'net_grams' => 0.0, 'making_pct' => 0.0, 'wastage_pct' => 0.0, 'charge' => 0.0, 'bracket_id' => null];
        }

        $gross = $cashExGst / $rate;                         // grams before charges
        ['making_pct' => $mc, 'wastage_pct' => $wc, 'bracket_id' => $id] = $this->forWeight($material, $gross);

        $chargePct = $mc + $wc;
        $charge = round($cashExGst * $chargePct / 100, 2);
        $net = round(($cashExGst - $charge) / $rate, 4);

        return [
            'gross_grams' => round($gross, 4),
            'net_grams' => $net,
            'making_pct' => $mc,
            'wastage_pct' => $wc,
            'charge' => $charge,
            'bracket_id' => $id,
        ];
    }

    /**
     * Current per-gram gold rate, 0 when none set. Queried fresh (not the memoised
     * LiveRate::latestFor cache) so a redeem in a long-running worker uses the live rate.
     */
    public function goldRate(string $country = 'IN'): float
    {
        return (float) (LiveRate::where('country', $country)->latest('effective_at')->value('gold') ?? 0);
    }
}
