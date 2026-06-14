<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-catalog-item stock-transfer margin rate card. Each seller level earns a
 * different % when it passes this item one hop down the chain. Area Dealer never
 * sells onward, so it has no rate.
 */
class StockTransferMargin extends Model
{
    protected $guarded = [];

    protected $casts = [
        'zonal_pct' => 'decimal:3',
        'district_pct' => 'decimal:3',
        'taluk_pct' => 'decimal:3',
        'wholesaler_pct' => 'decimal:3',
        'reseller_pct' => 'decimal:3',
    ];

    public function catalogProduct()
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** The % this card grants to a seller at the given branch level (0 if none). */
    public function pctFor(?string $level): float
    {
        $column = $level . '_pct';

        return in_array($column, [
            'zonal_pct', 'district_pct', 'taluk_pct', 'wholesaler_pct', 'reseller_pct',
        ], true) ? (float) $this->{$column} : 0.0;
    }
}
