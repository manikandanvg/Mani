<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dealer's legacy accrued billing margin (ci_users.billmargin — the unpaid balance shown in
 * legacy), waiting for Head Office to send it to the dealer's cash wallet by hand
 * (Commissions → Pending Billing Margin). Nothing is credited automatically.
 */
class PendingBillMargin extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'wallet';

    public const SOURCE_LEGACY = 'legacy';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_REJECTED = 'rejected';

    /** Board 2026-11-14: the old system had already paid it (up to 22-10-2026) — never payable here. */
    public const STATUS_CLOSED = 'closed';

    protected $casts = [
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class)->withTrashed(); }

    public function member() { return $this->belongsTo(Member::class); }

    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }

    /** The wallet this margin goes to: the branch's distributor login → its member account. */
    public function payeeMember(): ?Member
    {
        return $this->branch?->distributorUser?->memberAccount;
    }
}
