<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bond extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'contract';

    protected $guarded = [];

    protected $casts = [
        'bond_date' => 'date',
        'return_date' => 'date',
        'value' => 'decimal:2',
        'cbc_value' => 'decimal:2',
        'epin_value' => 'decimal:2',
        'lvlcom' => 'array',
    ];

    /** Maturity/expiry = start + the plan's validity (months). Null when no date/plan. */
    public function getExpiresOnAttribute(): ?\Illuminate\Support\Carbon
    {
        if (! $this->bond_date) {
            return null;
        }
        $months = (int) ($this->plan?->validity_months ?: $this->plan?->level_com_duration ?: 12);

        return $this->bond_date->copy()->addMonthsNoOverflow(max(1, $months));
    }

    /** First day billed in this system; every bond dated before it came over from the old one. */
    public const NEW_SYSTEM_FROM = '2026-11-01';

    /**
     * Billed in the OLD system (carried over by the roll-out): dated before the cut-over and
     * numbered the old way (this system numbers its bills "INV-…"). The old system already
     * issued — and may have redeemed — such a bill's QR, so no billing QR is ever minted for it
     * here (user 2026-11-20).
     */
    public function isFromOldSystem(): bool
    {
        return $this->bond_date
            && $this->bond_date->toDateString() < self::NEW_SYSTEM_FROM
            && ! str_starts_with((string) $this->invoice_no, 'INV-');
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function plan() { return $this->belongsTo(Plan::class); }

    /**
     * Bonds whose scheme gives Earning Potential (board 2026-11-18): Gold Saving PLUS 1 and the
     * G10 gold / silver purchase plans give none (plans.counts_for_ep = 0). Their bond value is
     * still the instalment / bill and must not be touched — only EP ignores it.
     */
    public function scopeEpBearing($query)
    {
        return $query->whereHas('plan', fn ($q) => $q->where('counts_for_ep', true));
    }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function mou() { return $this->belongsTo(Mou::class); }
    public function memberContract() { return $this->hasOne(MemberContract::class); }
    public function rdEntries() { return $this->hasMany(RdEntry::class); }
}
