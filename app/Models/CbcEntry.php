<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CbcEntry extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'commission';

    protected $casts = [
        'cbc_date' => 'date',
        'paid_on' => 'date',
        'worth' => 'decimal:2',
    ];

    public function bond() { return $this->belongsTo(Bond::class); }
    public function member() { return $this->belongsTo(Member::class); }
}
