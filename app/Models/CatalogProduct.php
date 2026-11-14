<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Trade catalog item (Gold / Silver / Vessel). Priced at the live metal rate, not a
 * fixed price. Stocked per branch and sold via the admin Sales module.
 */
class CatalogProduct extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'master';

    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'default_weight' => 'decimal:4',
        'making_charge_pct' => 'decimal:3',
        'wastage_charge_pct' => 'decimal:3',
        'gm_margin' => 'decimal:2',          // INR per gram redemption margin (legacy tbl_product.margin)
        'hallmark_charge' => 'decimal:2',
        'gst_pct' => 'decimal:3',
        'is_active' => 'boolean',
        'is_custom_order' => 'boolean',
    ];

    public function category() { return $this->belongsTo(Category::class, 'category_id'); }
    public function subcategory() { return $this->belongsTo(Category::class, 'subcategory_id'); }

    /**
     * Stock unit model (board 2026-08-10): stock.quantity holds PIECES for metal /
     * vessel products (85 = eighty-five 500 g bars) and RUPEES for cash. These two
     * converters keep every stock mutation and display on that single definition.
     */
    /**
     * Grams per piece. Legacy-imported items carried no default_weight, so the figure is
     * read off the name ("8 g Gold", "500 mg Silver") when the column is empty
     * (board 2026-10-31: app price list showed every item at ₹0).
     */
    /**
     * Making + wastage % for this item — THE one rule, used by billing, redemption and the app
     * price list (user 2026-11-14):
     *
     *   1. the product's own percentages;
     *   2. when the product carries none: the Charge Brackets slab for its weight (safety net for
     *      an item created without charges);
     *   3. neither → source 'none': a gold / silver item must not be billed or redeemed like that.
     *
     * @return array{making_pct: float, wastage_pct: float, source: string}  source = product | bracket | none
     */
    public function charges(?float $grams = null): array
    {
        $making = (float) $this->making_charge_pct;
        $wastage = (float) $this->wastage_charge_pct;
        if ($making + $wastage > 0) {
            return ['making_pct' => $making, 'wastage_pct' => $wastage, 'source' => 'product'];
        }
        if (! config('features.metal_charge_fallback', true)) {
            return ['making_pct' => 0.0, 'wastage_pct' => 0.0, 'source' => 'product'];   // switched off: as entered
        }
        if (in_array($this->material, ['gold', 'silver'], true)) {
            $grams = (float) $grams > 0 ? (float) $grams : $this->unitWeight();
            $slab = app(\App\Services\Charges\ChargeResolver::class)->forWeight($this->material, $grams);
            if (! empty($slab['bracket_id']) && ((float) $slab['making_pct'] + (float) $slab['wastage_pct']) > 0) {
                return ['making_pct' => (float) $slab['making_pct'], 'wastage_pct' => (float) $slab['wastage_pct'], 'source' => 'bracket'];
            }
        }

        return ['making_pct' => 0.0, 'wastage_pct' => 0.0, 'source' => 'none'];
    }

    /** A gold / silver stock item that has no making + wastage on the product and no bracket either. */
    public function lacksCharges(?float $grams = null): bool
    {
        return in_array($this->material, ['gold', 'silver'], true) && ! $this->is_custom_order
            && $this->charges($grams)['source'] === 'none';
    }

    public function unitWeight(): float
    {
        $w = (float) $this->default_weight;
        if ($w > 0 || $this->material === 'cash') {
            return $w;
        }

        return self::weightFromName(\App\Support\Translatable::pick($this->name) ?? '');
    }

    /** "8 g Gold" → 8.0, "500 mg Silver" → 0.5, "1 kg" → 1000.0; 0 when no weight is named. */
    public static function weightFromName(string $name): float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(mg|g|gm|gms|gram|grams|kg)\b/iu', $name, $m)) {
            return 0.0;
        }
        $n = (float) str_replace(',', '.', $m[1]);

        return match (strtolower($m[2])) {
            'mg' => round($n / 1000, 4),
            'kg' => $n * 1000,
            default => $n,
        };
    }

    public function piecesFromWeight(float $grams): float
    {
        if ($this->material === 'cash') {
            return $grams;   // "weight" is the rupee amount for cash lines
        }
        $unit = $this->unitWeight();

        return $unit > 0 ? round($grams / $unit, 4) : $grams;
    }

    public function gramsFromPieces(float $pieces): float
    {
        if ($this->material === 'cash') {
            return $pieces;
        }
        $unit = $this->unitWeight();

        return $unit > 0 ? round($pieces * $unit, 4) : $pieces;
    }
}
