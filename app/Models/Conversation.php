<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function participants() { return $this->hasMany(ConversationParticipant::class); }
    public function messages() { return $this->hasMany(Message::class); }
}
