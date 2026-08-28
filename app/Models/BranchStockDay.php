<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Daily stock snapshot per branch × metal item taken at the 8 PM auto-close
 * (board 2026-08-29): quantity vs the Opening level. is_short = below Opening.
 * Feeds the STOCK_KEPT task and the day-by-day chart (admin + app).
 */
class BranchStockDay extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:4',
        'opening_qty' => 'decimal:4',
        'is_short' => 'boolean',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
}
