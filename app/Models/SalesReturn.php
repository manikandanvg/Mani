<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer sales return (board 2026-08-26): coins / metal a distributor takes back
 * from a customer — typically accumulated RD 100 mg gold coins applied as payment on a
 * customised order. Lifecycle: pending (agreed collection date & time) → collected
 * (coins in the branch's stock) → relayed (moved on to the supplier when the linked
 * order is approved); or cancelled.
 */
class SalesReturn extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_RELAYED = 'relayed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:4',
        'grams' => 'decimal:4',
        'rate' => 'decimal:2',
        'credit_value' => 'decimal:2',
        'collect_on' => 'datetime',
        'collected_at' => 'datetime',
        'relayed_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }

    public function member() { return $this->belongsTo(Member::class); }

    public function catalogProduct() { return $this->belongsTo(CatalogProduct::class); }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** The customised order this return's value was applied to (if any). */
    public function orderRequest() { return $this->hasOne(BranchOrderRequest::class, 'sales_return_id'); }
}
