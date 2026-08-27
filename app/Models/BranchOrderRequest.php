<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchOrderRequest extends Model
{
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
