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
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'gold_rate' => 'decimal:2',
        'silver_rate' => 'decimal:2',
        'taxable_total' => 'decimal:2',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'dealer_created' => 'boolean',
    ];

    public function lines() { return $this->hasMany(RedemptionLine::class); }
    public function qr() { return $this->belongsTo(RedeemableQr::class, 'redeemable_qr_id'); }

    /** The branch restock order raised for this redemption (if any). */
    public function restockOrder()
    {
        return $this->hasOne(BranchOrderRequest::class, 'source_ref')
            ->where('source', BranchOrderService::SOURCE_REDEMPTION);
    }
    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
