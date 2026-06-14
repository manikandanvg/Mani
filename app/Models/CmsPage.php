<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    protected $casts = [
        'title' => 'array',
        'body' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }
}
