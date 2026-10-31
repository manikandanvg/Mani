<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One closed-wallet withdrawal: member scanned the branch L-BOX QR, wallet balance
 * moved to the branch (announced by the box), branch incharge disburses gold/cash.
 */
class WalletWithdrawal extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'wallet';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
