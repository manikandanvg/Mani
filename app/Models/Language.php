<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_rtl' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
