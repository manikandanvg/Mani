<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchOrderRequest extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'orders';

    protected $casts = [
        'cross_total' => 'decimal:2',
        'gst_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'coin_credit' => 'decimal:2',
        'pay_cash' => 'decimal:2',
        'pay_wallet' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'quote_extra' => 'decimal:2',
        'customer_details' => 'array',
        'delivery_date' => 'date',
        'coin_pickup_on' => 'datetime',
        'coin_captured_at' => 'datetime',
        'quote_debited_at' => 'datetime',
        'delivered_at' => 'datetime',
        'no_of_items' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function attachments()
    {
        return $this->hasMany(BranchOrderAttachment::class, 'order_request_id');
    }

    public function lines()
    {
        return $this->hasMany(BranchOrderLine::class, 'order_request_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** For redemption-sourced requests, the redemption invoice this restock is for. */
    public function redemptionInvoice()
    {
        return $this->belongsTo(RedemptionInvoice::class, 'source_ref');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Where the order / its pieces currently sit (customized orders travel the ladder). */
    public function currentBranch()
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    /** Road map of a customized order. */
    public function events()
    {
        return $this->hasMany(BranchOrderEvent::class, 'order_request_id');
    }

    /** Existing customer a customised order is for (new customers live in customer_details only). */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /** Customer coins applied to a customised order (board 2026-08-26). */
    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    /** Display name of the customer a customised order is for (member, or the captured new customer). */
    public function customerName(): string
    {
        if ($this->member) {
            return $this->member->name . ' (' . $this->member->member_code . ')';
        }
        $c = (array) ($this->customer_details ?? []);

        return trim(($c['name'] ?? '') . (! empty($c['phone']) ? ' · ' . $c['phone'] : '')) ?: '—';
    }

    /** Bespoke gold/silver order built on the Customize Order Form. */
    /**
     * A cash-stock order: the dealer paid MONEY to Head Office for a cash-stock top-up. No
     * dealer supplies cash, so only Head Office may decide it (complaint 2026-11-11).
     */
    public function hasCashLine(): bool
    {
        return $this->relationLoaded('lines')
            ? $this->lines->contains('material', 'cash')
            : $this->lines()->where('material', 'cash')->exists();
    }

    /**
     * May this login approve / reject the order? Head Office staff always; a dealer only as
     * the buyer's VALID supplier (board matrix) and never for a cash-stock order.
     */
    public function decidableBy(?\App\Models\User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (! $user->isDistributor()) {
            return true;
        }
        $mine = $user->branch_id ? Branch::find($user->branch_id) : null;

        return $mine !== null && $mine->maySupply($this->branch) && ! $this->hasCashLine();
    }

    /** Other standing orders paid by the same URN (a payment split over several orders). */
    public function urnSiblings()
    {
        return $this->hasMany(self::class, 'urn_no', 'urn_no')
            ->whereNotIn('status', ['rejected', 'cancelled']);
    }

    /** "Paid ₹X · shared with ORD-…, ordered ₹Y of ₹X" — what the approver checks against the bank. */
    public function urnNote(): ?string
    {
        if (blank($this->urn_no)) {
            return null;
        }
        $paid = $this->urn_amount !== null ? 'Paid ₹' . \App\Support\Money::group((float) $this->urn_amount) : null;
        $all = $this->urnSiblings;
        $others = $all->where('id', '!=', $this->id);
        if ($others->isEmpty()) {
            return $paid;
        }

        return trim(($paid ? $paid . ' · ' : '') . 'SHARED with ' . $others->pluck('request_no')->implode(', ')
            . ' — ₹' . \App\Support\Money::group((float) $all->sum('grand_total')) . ' ordered on this URN');
    }

    /** Was this order approved / rejected by a dealer login instead of Head Office? */
    public function decidedByDealer(): bool
    {
        return $this->approved_by !== null && (bool) $this->approver?->isDistributor();
    }

    public function isCustomize(): bool
    {
        return $this->source === \App\Services\BranchOrderService::SOURCE_CUSTOMIZE;
    }

    /** Human label for the payment mode (digi_cash is shown as the branch wallet). */
    public static function paymentLabel(?string $type): string
    {
        return match ($type) {
            'digi_cash' => 'Branch Wallet',
            'split' => 'Cash stock + Branch Wallet',
            null, '' => '—',
            default => ucfirst($type),
        };
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
