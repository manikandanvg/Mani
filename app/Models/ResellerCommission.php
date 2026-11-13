<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerCommission extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'commission';

    /**
     * com_type_id values. 1-4 mirror the legacy tbl_reseller_com ids; 5 was added
     * 2026-07 so RD renewal margins stop masquerading as gold margin in approval.
     */
    /** Imported legacy history — settled in legacy, never approvable again (user 2026-11-02). */
    public const SOURCE_LEGACY = 'legacy';

    public const COM_BILL_MARGIN = 1;

    public const COM_GOLD_MARGIN = 2;

    public const COM_SILVER_MARGIN = 3;

    public const COM_STOCK_TRANSFER_MARGIN = 4;

    public const COM_RD_RENEWAL_MARGIN = 5;

    protected $guarded = [];

    protected $casts = [
        'bill_date' => 'date',
        'paid_on' => 'date',
        'com_value' => 'decimal:2',
        'tds' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    /** The BUYER the bill was raised for (legacy tbl_reseller_com.referrance) — never the earner. */
    public function referenceMember() { return $this->belongsTo(Member::class, 'reference_member_id'); }

    /**
     * Margins EARNED by this member: the rows of the branch(es) whose distributor login is this
     * member — the same payee CommissionApprovalService credits. reference_member_id is the buyer,
     * so listing by it showed the dealer's margin in the customer's app (complaint 2026-11-13).
     * Imported legacy history (settled in legacy) is left out.
     */
    public function scopeEarnedBy($query, Member $member)
    {
        $branchIds = User::query()
            ->where('member_code', $member->member_code)
            ->where('status', 'active')
            ->whereNotNull('branch_id')
            ->whereHas('roles', fn ($q) => $q->where('name', 'distributor'))
            ->pluck('branch_id');

        return $query->whereIn('branch_id', $branchIds)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::SOURCE_LEGACY));
    }
}
