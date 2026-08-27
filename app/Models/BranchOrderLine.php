<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchOrderLine extends Model
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
        'billed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(BranchOrderRequest::class, 'order_request_id');
    }

    public function catalogProduct()
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** The G10 invoice this custom piece was finally billed on. */
    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
