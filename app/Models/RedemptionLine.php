<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedemptionLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'unit_weight' => 'decimal:4',
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'making' => 'decimal:2',
        'wastage' => 'decimal:2',
        'gst_pct' => 'decimal:3',
        'amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice() { return $this->belongsTo(RedemptionInvoice::class, 'redemption_invoice_id'); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
}
