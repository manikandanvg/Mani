<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargeBracket extends Model
{
    protected $casts = [
        'wt_from' => 'decimal:4',
        'wt_to' => 'decimal:4',
        'making_pct' => 'decimal:3',
        'wastage_pct' => 'decimal:3',
    ];
}
