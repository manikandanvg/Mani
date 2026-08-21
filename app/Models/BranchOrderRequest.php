<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchOrderRequest extends Model
{
    protected $casts = [
        'cross_total' => 'decimal:2',
        'gst_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
