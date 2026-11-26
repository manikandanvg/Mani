<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the Pending Gold QR page — a READ-ONLY view (pending_legacy_qrs) over the two
 * legacy routes: INVOICE (redeemable_qrs, source legacy) and DIGIWD (digi_withdrawals).
 * Never saved; every action goes to the source record (see source()).
 */
class PendingLegacyQr extends Model
{
    public const ROUTE_INVOICE = 'INVOICE';

    public const ROUTE_DIGIWD = 'DIGIWD';

    protected $table = 'pending_legacy_qrs';

    protected $primaryKey = 'uid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'gram_worth' => 'decimal:4',
        'cash_worth' => 'decimal:2',
        'qr_sent' => 'boolean',
        'sent_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isDigiWd(): bool
    {
        return $this->route === self::ROUTE_DIGIWD;
    }

    /** The record this row stands for: the redeemable QR (INVOICE) or the withdrawal (DIGIWD). */
    public function source(): RedeemableQr|DigiWithdrawal
    {
        return $this->isDigiWd()
            ? DigiWithdrawal::findOrFail($this->source_id)
            : RedeemableQr::findOrFail($this->source_id);
    }

    public function save(array $options = []): bool
    {
        throw new \LogicException('pending_legacy_qrs is a read-only view.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('pending_legacy_qrs is a read-only view.');
    }

    public function member() { return $this->belongsTo(Member::class); }

    public function bond() { return $this->belongsTo(Bond::class); }

    public function branch() { return $this->belongsTo(Branch::class); }

    public function redeemBranch() { return $this->belongsTo(Branch::class, 'redeem_branch_id'); }

    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }
}
