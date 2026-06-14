<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionLedger extends Model
{
    protected $table = 'commission_ledger';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'tds' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'earned_on' => 'date',
        'paid_on' => 'date',
    ];

    public function member() { return $this->belongsTo(Member::class); }
    public function fromMember() { return $this->belongsTo(Member::class, 'from_member_id'); }
}
