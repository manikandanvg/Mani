<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate_to_base' => 'decimal:8',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
    ];
}
