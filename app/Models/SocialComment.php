<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialComment extends Model
{
    public function post() { return $this->belongsTo(SocialPost::class, 'post_id'); }
    public function author() { return $this->belongsTo(User::class, 'author_id'); }
}
