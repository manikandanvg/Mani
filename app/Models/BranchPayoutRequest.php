<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bundle of wallet withdrawals a branch asked Head Office to fund (board 2026-11-03).
 * HQ pays the bundle to the branch (bank transfer outside the system, reference typed in),
 * after which the branch may disburse each withdrawal at the counter.
 */
class BranchPayoutRequest extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'wallet';

    public const STATUSES = ['requested' => 'Requested', 'paid' => 'Paid by HQ', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawal::class, 'payout_request_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** The dealer's login/distributor ID (e.g. LJW24774) — shown as "branch name (userid)". */
    public function dealerUserId(): ?string
    {
        $code = $this->branch?->distributorUser?->member_code;

        return $code !== null && $code !== '' ? (string) $code : null;
    }

    /** The dealer's bank details (branches carry none of their own) — for HQ's transfer. */
    public function dealerBank(): ?Member
    {
        return $this->branch?->distributorUser?->memberAccount;
    }

    /** rows_count / amount follow the live rows (an HQ cancel of one row shrinks the bundle). */
    public function recount(): static
    {
        $live = $this->withdrawals()->where('status', '<>', 'cancelled');
        $this->forceFill(['rows_count' => (int) $live->count(), 'amount' => round((float) $live->sum('amount'), 2)])->save();

        return $this;
    }
}
