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
     * Old-system history already paid there (up to LEGACY_PAID_UPTO) is left out.
     */
    public function scopeEarnedBy($query, Member $member)
    {
        $branchIds = User::query()
            ->where('member_code', $member->member_code)
            ->where('status', 'active')
            ->whereNotNull('branch_id')
            ->whereHas('roles', fn ($q) => $q->where('name', 'distributor'))
            ->pluck('branch_id');

        // Same rule as payeeMember(): an old-system row belongs to the mapped ID written on it;
        // every other row to the billing branch's distributor login.
        $viaBranch = fn ($q) => $q->whereIn('branch_id', $branchIds);
        $noKnownMappedId = fn ($q) => $q->whereNull('mapped_uid')->orWhere('mapped_uid', '')
            ->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('members')->whereColumn('members.member_code', 'reseller_commissions.mapped_uid'));

        return $query->payable()->where(fn ($q) => $q
            ->where(fn ($new) => $new->where(fn ($s) => $s->whereNull('source')->orWhere('source', '!=', self::SOURCE_LEGACY))->where($viaBranch))
            ->orWhere(fn ($old) => $old->where('source', self::SOURCE_LEGACY)->where(fn ($who) => $who
                ->where('mapped_uid', $member->member_code)
                ->orWhere(fn ($fallback) => $fallback->where($viaBranch)->where($noKnownMappedId)))));
    }

    /**
     * Who is paid this margin — the dealer's mapped ID:
     *   an old-system row   the mapped ID the old system wrote on it (tbl_reseller_com.mappeduid);
     *                       when that names no distributor here, the billing branch's login;
     *   every other row     the billing branch's distributor login → its member account.
     */
    public function payeeMember(): ?Member
    {
        if ($this->source === self::SOURCE_LEGACY && filled($this->mapped_uid)
            && ($mapped = Member::where('member_code', $this->mapped_uid)->first())) {
            return $mapped;
        }

        return $this->branch?->distributorUser?->memberAccount;
    }

    /**
     * Lord's board 2026-11-14: the old system PAID every billing margin up to 22-10-2026 (its
     * last payout run, tbl_digi_waout trid 386, 18–22 Oct) without ever marking the rows paid.
     * So of the imported rows only 23-10-2026 → 31-10-2026 are still owed; they are paid through
     * Commission Approval like the new system's own margins, with the same TDS and service charge.
     */
    public const LEGACY_PAID_UPTO = '2026-10-22';

    /** Rows the new system pays or has paid: its own, and the old system's from 23-10-2026 on. */
    public function scopePayable($query)
    {
        return $query->where(fn ($q) => $q->whereNull('source')
            ->orWhere('source', '!=', self::SOURCE_LEGACY)
            ->orWhereDate('bill_date', '>', self::LEGACY_PAID_UPTO));
    }
}
