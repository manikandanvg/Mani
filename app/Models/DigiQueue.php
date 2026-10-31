<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DigiQueue extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'digi';

    protected $table = 'digi_queue';

    protected $casts = [
        'gram_worth' => 'decimal:4',
        'cash_worth' => 'decimal:2',
        'qr_sent' => 'boolean',
    ];

    public function member() { return $this->belongsTo(Member::class); }
    public function redeemBranch() { return $this->belongsTo(Branch::class, 'redeem_branch_id'); }
}
