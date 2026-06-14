<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    protected $casts = [
        'last_read_at' => 'datetime',
        'is_admin' => 'boolean',
    ];

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function user() { return $this->belongsTo(User::class); }
}
