<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One closed-wallet withdrawal: member scanned the branch L-BOX QR, wallet balance
 * moved to the branch (announced by the box). Board 2026-11-03 — the branch never fronts
 * the cash: pending → requested (bundled to HQ) → admin_paid (HQ funded the branch)
 * → disbursed (handed over at the counter). Only HQ cancels (refund to the wallet).
 */
class WalletWithdrawal extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'wallet';

    public const STATUSES = [
        'pending' => 'Pending',
        'requested' => 'Requested from HQ',
        'admin_paid' => 'Admin paid',
        'disbursed' => 'Disbursed',
        'cancelled' => 'Cancelled',
    ];

    /** Statuses still owed to the member — a cancel refunds, nothing else moves money. */
    public const OPEN = ['pending', 'requested', 'admin_paid'];

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

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(BranchPayoutRequest::class, 'payout_request_id');
    }

    /** Where the row came from: an app scan at the box, or one of the two dealer-panel pots. */
    public function source(): string
    {
        return $this->device_id !== null ? 'lbox' : ($this->wallet === 'branch_digi' ? 'panel_branch_digi' : 'panel_member_cash');
    }
}
