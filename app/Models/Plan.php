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
     * Ranking-BV multiplier: rank_factor is the explicit 0-100 percentage of the
     * ORIGINAL billed value that counts as pure/ranking BV (schema v2); excluded
     * plans contribute 0.
     */
    public function rankFactor(): float
    {
        if (! $this->counts_for_rank) {
            return 0.0;
        }

        return (float) $this->rank_factor / 100;
    }

    /**
     * Ranking (unpure) BV contributed by a bond. rank_factor % applies to the ORIGINAL
     * billed value, but bonds store the allocation_bv-adjusted amount — so scale back up
     * first. G5 example: ₹1,00,000 bill × 50% BV → bond 50,000; unpure = 1,00,000 × 50%
     * = 50,000 (NOT 50% of 50,000).
     */
    public function unpureFromBondValue(float $bondValue): float
    {
        $factor = $this->rankFactor();
        if ($factor <= 0 || $bondValue <= 0) {
            return 0.0;
        }

        $alloc = (float) $this->allocation_bv;
        $original = ($alloc > 0 && $alloc < 100) ? $bondValue * 100 / $alloc : $bondValue;

        return round($original * $factor, 2);
    }
}
