<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Gold Saving (G11) settlement made by Head Office — see GoldSavingSettlementService. */
class RdSettlement extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'settlement';

    protected $guarded = [];

    protected $casts = [
        'settled_on' => 'date',
        'first_due' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'bonus' => 'decimal:2',
        'coin_price' => 'decimal:2',
        'deduction' => 'decimal:2',
        'worth' => 'decimal:2',
    ];

    public function contract() { return $this->belongsTo(MemberContract::class, 'member_contract_id'); }
    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function qr() { return $this->belongsTo(RedeemableQr::class, 'redeemable_qr_id'); }
}
