<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerCommission extends Model
{
    /**
     * com_type_id values. 1-4 mirror the legacy tbl_reseller_com ids; 5 was added
     * 2026-07 so RD renewal margins stop masquerading as gold margin in approval.
     */
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
    public function referenceMember() { return $this->belongsTo(Member::class, 'reference_member_id'); }
}
