<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
    ];

    public function parent() { return $this->belongsTo(Category::class, 'parent_id'); }
    public function children() { return $this->hasMany(Category::class, 'parent_id'); }
    public function products() { return $this->hasMany(Product::class); }

    public function imageUrl(): ?string
    {
        return media_url($this->image_path);
    }

    public function scopeDomain($q, string $domain) { return $q->where('domain', $domain); }
    public function scopeRoots($q) { return $q->whereNull('parent_id'); }
}
