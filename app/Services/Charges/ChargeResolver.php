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
     * both ends (wt_from ≤ g ≤ wt_to); the narrowest matching slab wins.
     *
     * The slabs are keyed like the legacy table (…–0.1000, 0.1010–0.3000, …), which leaves a
     * hair-line gap between two slabs: a weight inside a gap (or lighter than the first slab)
     * takes the NEXT slab up — a piece is never charge-free just because its weight fell
     * between two rows (user 2026-11-05). Zeros when the weight is beyond the last slab or the
     * material has none (caller treats "no bracket" as "no charge" — unchanged).
     */
    public function forWeight(string $material, float $grams): array
    {
        $b = $grams > 0
            ? (ChargeBracket::where('material', $material)
                ->where('wt_from', '<=', $grams)
                ->where('wt_to', '>=', $grams)
                ->orderByRaw('(wt_to - wt_from) asc')   // prefer the tightest matching slab
                ->first()
                ?? ChargeBracket::where('material', $material)->where('wt_from', '>', $grams)->orderBy('wt_from')->first())
            : null;

        return [
            'making_pct' => (float) ($b->making_pct ?? 0),
            'wastage_pct' => (float) ($b->wastage_pct ?? 0),
            'bracket_id' => $b?->id,
        ];
    }

    /**
     * Convert a cash amount (ex-GST) into net redeemable gold grams, taking making + wastage
     * for the resolved weight slab back out — the legacy Lscript reversal, to the paisa:
     *
     *   approx weight = cash ÷ rate              → picks the slab
     *   metal value   = cash × 100 ÷ (100 + mc + wc)
     *   net grams     = metal value ÷ rate       (so net grams + its charges = the cash)
     *
     * e.g. ₹25,000 incl. GST → ₹24,271.84 → slab 2.5 + 2.5 % → ₹23,116.04 → 1.8159 g @ ₹12,730.
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
        $metal = $cashExGst * 100 / (100 + $chargePct);
        $charge = round($cashExGst - $metal, 2);
        $net = round($metal / $rate, 4);

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
     * Gold grams a GST-INCLUSIVE rupee worth buys (a scheme's gold-purchase share, a QR's cash
     * worth): strip the GST, then reverse making + wastage for the weight slab — cashToGold().
     */
    public function gramsForWorth(float $worthInclGst, ?float $goldRatePerGram = null, float $gstPct = 3.0): float
    {
        return $this->cashToGold($worthInclGst * 100 / (100 + $gstPct), $goldRatePerGram)['net_grams'];
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
