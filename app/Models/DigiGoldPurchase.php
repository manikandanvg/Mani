<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An online digi-gold buy (Razorpay). Grams credit only on verified payment. */
class DigiGoldPurchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'grams' => 'decimal:4',
        'paid_at' => 'datetime',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
