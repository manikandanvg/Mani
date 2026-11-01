<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberContract extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'contract';

    public const SOURCE_SYSTEM = 'system';

    /** Carried over from tbl_bond by `contracts:backfill-legacy`; the settlement engine skips these. */
    public const SOURCE_LEGACY = 'legacy';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function branch() { return $this->belongsTo(Branch::class); }

    /** Admin-generated settlements (board phase 2, 2026-08-28) — at most one per contract. */
    public function settlements() { return $this->hasMany(ContractSettlement::class); }

    public function isExpired(): bool
    {
        return \App\Services\ContractSettlementService::isExpired($this);
    }
}
