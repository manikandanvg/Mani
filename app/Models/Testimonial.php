<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $casts = [
        'body' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }
}
