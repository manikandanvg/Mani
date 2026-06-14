<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseLine extends Model
{
    public $timestamps = false;

    protected $casts = [
        'weight' => 'decimal:4',
        'rate' => 'decimal:2',
        'making_charge_pct' => 'decimal:3',
        'wastage_charge_pct' => 'decimal:3',
        'hallmark_charge' => 'decimal:2',
        'gst_pct' => 'decimal:3',
        'line_total' => 'decimal:2',
    ];

    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
}
