<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleLine extends Model
{
    public $timestamps = false;

    protected $casts = [
        'qty' => 'decimal:4',
        'making' => 'decimal:2',
        'wastage' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice() { return $this->belongsTo(SalesInvoice::class, 'invoice_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }
}
