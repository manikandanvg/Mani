<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A redeemable stock QR issued against a bond/contract. The qr_code is the token the
 * PNG encodes; the customer redeems it at a branch (next phase). Replaces the legacy
 * tbl_digi_queue "GOLD / INVOICE" rows — no longer branded "Digi".
 */
class RedeemableQr extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gram_worth' => 'decimal:4',
        'cash_worth' => 'decimal:2',
        'qr_sent' => 'boolean',
        'sent_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function bond() { return $this->belongsTo(Bond::class); }

    public function member() { return $this->belongsTo(Member::class); }

    public function branch() { return $this->belongsTo(Branch::class); }

    public function redeemBranch() { return $this->belongsTo(Branch::class, 'redeem_branch_id'); }
}
