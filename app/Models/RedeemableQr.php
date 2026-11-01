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
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'redemption';

    public const SOURCE_SYSTEM = 'system';

    /** Legacy invoice QR staged by `legacy:stage-pending-qrs`: any branch, at the price of its own date. */
    public const SOURCE_LEGACY = 'legacy';

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
