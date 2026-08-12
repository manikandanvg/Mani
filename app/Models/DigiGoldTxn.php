<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gram-level ledger behind member_wallets.digi_gold_grams (Phase 3). */
class DigiGoldTxn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'grams' => 'decimal:4',
        'rate' => 'decimal:2',
        'value' => 'decimal:2',
        'fee' => 'decimal:2',
        'balance_after' => 'decimal:4',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
