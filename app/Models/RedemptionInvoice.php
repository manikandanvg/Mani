<?php

namespace App\Models;

use App\Services\BranchOrderService;
use Illuminate\Database\Eloquent\Model;

/**
 * The tax invoice raised when a stock QR is redeemed — the real sale (legacy
 * tbl_qrsalesinvoice). See [[redeemable-stock-qr]] / RedemptionService.
 */
class RedemptionInvoice extends Model
{
    use \App\Models\Concerns\LogsActivity;
    // soft-deleted = CANCELLED (a redemption made by mistake, undone by Head Office — 2026-11-17);
    // the row and its number stay on record but drop out of every register.
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public const ACTIVITY_CATEGORY = 'redemption';

    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'gold_rate' => 'decimal:2',
        'silver_rate' => 'decimal:2',
        'frozen_gold_rate' => 'decimal:2',
        'frozen_silver_rate' => 'decimal:2',
        'frozen_on' => 'date',
        'taxable_total' => 'decimal:2',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'dealer_created' => 'boolean',
    ];

    public function lines() { return $this->hasMany(RedemptionLine::class); }
    public function qr() { return $this->belongsTo(RedeemableQr::class, 'redeemable_qr_id'); }

    /**
     * The branch restock order raised for this redemption (if any) — raised here, or carried over
     * from the old system as a LEG- order (user 2026-11-16). Never more than one.
     */
    public function restockOrder()
    {
        return $this->hasOne(BranchOrderRequest::class, 'redemption_invoice_id');
    }
    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
