<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A support conversation between an app identity (Member/Customer) and HQ.
 * See migration 2026_06_14_140000.
 */
class SupportThread extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
        'owner_last_read_at' => 'datetime',
        'support_last_read_at' => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'thread_id');
    }

    /** Unread support replies for the app owner (messages after their read cursor). */
    public function ownerUnreadCount(): int
    {
        return $this->messages()
            ->where('sender', 'support')
            ->when($this->owner_last_read_at, fn ($q) => $q->where('created_at', '>', $this->owner_last_read_at))
            ->count();
    }
}
