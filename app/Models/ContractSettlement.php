<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-entered settlement of an EXPIRED contract (board phase 2, 2026-08-28): the
 * value typed on "Generate settlement" is credited to the distributor's cash wallet
 * the moment it is submitted, and the row is what the app lists under
 * My Earnings → Contract Settlement.
 */
class ContractSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function contract() { return $this->belongsTo(MemberContract::class, 'member_contract_id'); }
    public function member() { return $this->belongsTo(Member::class); }
    public function bond() { return $this->belongsTo(Bond::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
