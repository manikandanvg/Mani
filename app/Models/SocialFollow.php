<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialFollow extends Model
{
    public function follower() { return $this->belongsTo(User::class, 'follower_id'); }
    public function following() { return $this->belongsTo(User::class, 'following_id'); }
}
