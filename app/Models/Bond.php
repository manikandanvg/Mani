<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bond extends Model
{
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

    public function member() { return $this->belongsTo(Member::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function mou() { return $this->belongsTo(Mou::class); }
    public function memberContract() { return $this->hasOne(MemberContract::class); }
    public function rdEntries() { return $this->hasMany(RdEntry::class); }
}
