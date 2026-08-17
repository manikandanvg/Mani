<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialPost extends Model
{
    use SoftDeletes;

    protected $casts = [
        'pinned' => 'boolean',
        'is_hidden' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function author() { return $this->belongsTo(User::class, 'author_id'); }
    public function poster() { return $this->morphTo(); }   // app author (Member/Customer)
    public function media() { return $this->hasMany(SocialPostMedia::class, 'post_id'); }
    public function comments() { return $this->hasMany(SocialComment::class, 'post_id'); }
    public function likes() { return $this->hasMany(SocialLike::class, 'post_id'); }

    // App-facing (Member/Customer) engagement — Phase 5a.
    public function reactions() { return $this->hasMany(SocialPostReaction::class, 'post_id'); }
    public function appComments() { return $this->hasMany(SocialPostComment::class, 'post_id'); }

    /** Posts visible in the app feed: published (or unscheduled) and not member-private. */
    public function scopePublished($query)
    {
        return $query->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
