<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only row of the commission_entries VIEW — every stream in one place:
 * IC/GAP (commission_ledger) + CBC (cbc_entries) + the 5 margins
 * (reseller_commissions). Backs the "Commission Ledgers" viewing screen ONLY;
 * writes always go to the underlying tables via their services.
 */
class CommissionEntry extends Model
{
    protected $table = 'commission_entries';

    protected $primaryKey = 'uid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'earned_on' => 'date',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
        'tds' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fromMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'from_member_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
