<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An RD / Gold-Saving auto-debit registration (Razorpay Subscription) against a bond.
 * Opt-in; coexists with the manual cash-stock renewal. e-mandate charges are online —
 * they do NOT spend branch cash stock and earn NO bill-margin (unlike manual renewal).
 */
class RdMandate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'total_count' => 'integer',
        'paid_count' => 'integer',
        'next_charge_on' => 'date',
        'meta' => 'array',
    ];

    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function branch() { return $this->belongsTo(Branch::class); }

    public function isActive(): bool
    {
        return in_array($this->status, ['authenticated', 'active', 'pending'], true);
    }
}
