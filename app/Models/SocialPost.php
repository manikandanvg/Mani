<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialPost extends Model
{
    use SoftDeletes;

    public function author() { return $this->belongsTo(User::class, 'author_id'); }
    public function media() { return $this->hasMany(SocialPostMedia::class, 'post_id'); }
    public function comments() { return $this->hasMany(SocialComment::class, 'post_id'); }
    public function likes() { return $this->hasMany(SocialLike::class, 'post_id'); }
}
