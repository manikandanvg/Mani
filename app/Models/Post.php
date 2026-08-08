<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $casts = [
        'title' => 'array',
        'excerpt' => 'array',
        'body' => 'array',
        'published_at' => 'datetime',
        'is_published' => 'boolean',
    ];

    public function imageUrl(): ?string
    {
        return media_url($this->image_path);
    }

    public function scopePublished($q)
    {
        return $q->where('is_published', true)->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeType($q, string $type) { return $q->where('type', $type); }
}
