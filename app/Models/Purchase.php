<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $casts = [
        'purchase_date' => 'date',
        'gold_rate' => 'decimal:2',
        'silver_rate' => 'decimal:2',
        'gross_total' => 'decimal:2',
        'gst_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function lines() { return $this->hasMany(PurchaseLine::class); }
}
