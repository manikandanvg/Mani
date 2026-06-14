<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPostMedia extends Model
{
    public $timestamps = false;

    protected $table = 'social_post_media';

    public function post() { return $this->belongsTo(SocialPost::class, 'post_id'); }
}
