<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DigiOrder extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'digi';

    /** Legacy opening balance waiting on Commissions → Pending Digi. */
    public const STATUS_PENDING = 'pending';

    /** Sent to the purse (digi_gold_txns line + wallet grams). */
    public const STATUS_CREDITED = 'credited';

    protected $guarded = [];

    protected $casts = [
        'gold_wt' => 'decimal:4',
        'value' => 'decimal:2',
        'rate_on_date' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function member() { return $this->belongsTo(Member::class); }

    public function branch() { return $this->belongsTo(Branch::class); }

    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }
}
