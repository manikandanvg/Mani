<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $casts = [
        'question' => 'array',
        'answer' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }
}
