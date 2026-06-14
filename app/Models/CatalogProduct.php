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
}
