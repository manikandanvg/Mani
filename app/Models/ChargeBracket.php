<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargeBracket extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'master';

    protected $casts = [
        'wt_from' => 'decimal:4',
        'wt_to' => 'decimal:4',
        'making_pct' => 'decimal:3',
        'wastage_pct' => 'decimal:3',
    ];
}
