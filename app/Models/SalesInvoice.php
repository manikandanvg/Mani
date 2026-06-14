<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesInvoice extends Model
{
    protected $casts = [
        'date' => 'date',
        'cross_total' => 'decimal:2',
        'discount' => 'decimal:2',
        'net_total' => 'decimal:2',
        'sgst' => 'decimal:2',
        'cgst' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'received' => 'decimal:2',
        'gold_rate' => 'decimal:2',
        'silver_rate' => 'decimal:2',
        'diamond_rate' => 'decimal:2',
    ];

    public function customer() { return $this->belongsTo(Member::class, 'customer_member_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function lines() { return $this->hasMany(SaleLine::class, 'invoice_id'); }
}
