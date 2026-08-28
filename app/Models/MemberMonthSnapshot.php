<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** GBV / BV / directs on the 1st of the month — baseline for the GBV_GROWTH task. */
class MemberMonthSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'gbv' => 'decimal:2',
        'bv' => 'decimal:2',
        'direct_count' => 'integer',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
