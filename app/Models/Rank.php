<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rank extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name' => 'array',           // translatable {en:.., ta:..}
        'tier_template' => 'array',
        'is_active' => 'boolean',
    ];
}
