<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mou extends Model
{
    protected $casts = [
        'title' => 'array',   // translatable
        'terms' => 'array',   // translatable rich text
    ];

    public function plans() { return $this->hasMany(Plan::class); }
}
