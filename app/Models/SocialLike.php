<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialLike extends Model
{
    public function post() { return $this->belongsTo(SocialPost::class, 'post_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
