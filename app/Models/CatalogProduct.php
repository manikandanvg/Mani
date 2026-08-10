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
    ];

    public function category() { return $this->belongsTo(Category::class, 'category_id'); }
    public function subcategory() { return $this->belongsTo(Category::class, 'subcategory_id'); }

    /**
     * Stock unit model (board 2026-08-10): stock.quantity holds PIECES for metal /
     * vessel products (85 = eighty-five 500 g bars) and RUPEES for cash. These two
     * converters keep every stock mutation and display on that single definition.
     */
    public function piecesFromWeight(float $grams): float
    {
        if ($this->material === 'cash') {
            return $grams;   // "weight" is the rupee amount for cash lines
        }
        $unit = (float) $this->default_weight;

        return $unit > 0 ? round($grams / $unit, 4) : $grams;
    }

    public function gramsFromPieces(float $pieces): float
    {
        if ($this->material === 'cash') {
            return $pieces;
        }
        $unit = (float) $this->default_weight;

        return $unit > 0 ? round($pieces * $unit, 4) : $pieces;
    }
}
