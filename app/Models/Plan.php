<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'ic_schedule' => 'array',
        'level_schedule' => 'array',
        'min_value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'max_sales_value' => 'decimal:2',
        'allocation_bv' => 'decimal:3',
        'allocation_cont' => 'decimal:3',
        'cbc_value' => 'decimal:3',
        'renewal_margin' => 'decimal:3',
        'is_redeem' => 'boolean',
        'is_contract' => 'boolean',
        'is_invoice' => 'boolean',
        'is_active' => 'boolean',
        'useraccess' => 'boolean',
        'settlement_qr_pct' => 'decimal:2',
        'settlement_close' => 'boolean',
        'settlement_suspend' => 'boolean',
        'counts_for_rank' => 'boolean',
        'rank_factor' => 'decimal:2',
    ];

    public function mou() { return $this->belongsTo(Mou::class); }

    public function rdQrProduct() { return $this->belongsTo(CatalogProduct::class, 'rd_qr_product_id'); }

    /**
     * Ranking-BV multiplier: rank_factor is the explicit 0-100 percentage of billed
     * value that counts as pure/ranking BV (schema v2); excluded plans contribute 0.
     */
    public function rankFactor(): float
    {
        if (! $this->counts_for_rank) {
            return 0.0;
        }

        return (float) $this->rank_factor / 100;
    }
}
