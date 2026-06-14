<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberWallet extends Model
{
    protected $primaryKey = 'member_id';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'cash_balance' => 'decimal:2',
        'coupon_balance' => 'decimal:2',
        'epin_balance' => 'decimal:2',
        'earning_total' => 'decimal:2',
        'withdrawn_total' => 'decimal:2',
        'digi_gold_grams' => 'decimal:4',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
