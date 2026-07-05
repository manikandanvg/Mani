<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** An app user's (Member/Customer) reaction to a community post. Phase 5a. */
class SocialPostReaction extends Model
{
    protected $guarded = [];

    public function post()
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function reactor(): MorphTo
    {
        return $this->morphTo();
    }
}
