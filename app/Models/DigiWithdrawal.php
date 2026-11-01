<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DigiWithdrawal extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'digi';

    /** A legacy withdrawal request (tbl_digiwithdrawal PENDING). */
    public const SOURCE_REQUEST = 'request';

    /** A dealer-side cash ticket (legacy digi_queue DIGIWD) with no request behind it. */
    public const SOURCE_TICKET = 'ticket';

    public const DECISION_CASH = 'cash';

    public const DECISION_DIGI = 'digi';

    protected $guarded = [];

    protected $casts = [
        'gold_wt' => 'decimal:4',
        'worth' => 'decimal:2',
        'rate' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function member() { return $this->belongsTo(Member::class); }

    public function decidedBy() { return $this->belongsTo(User::class, 'decided_by'); }

    //
}
