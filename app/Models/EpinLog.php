<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpinLog extends Model
{
    protected $casts = [
        'unit_price' => 'decimal:2',
        'net_total' => 'decimal:2',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
