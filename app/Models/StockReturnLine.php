<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReturnLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'weight' => 'decimal:3',
        'rate' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function stockReturn() { return $this->belongsTo(StockReturn::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
}
