<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message in a support thread. `sender` = 'user' (the app identity) or
 * 'support' (HQ). `source` = 'app' or 'whatsapp' (inbound WhatsApp, Phase 4c-2).
 */
class SupportMessage extends Model
{
    protected $guarded = [];

    public function thread()
    {
        return $this->belongsTo(SupportThread::class, 'thread_id');
    }

    public function supportUser()
    {
        return $this->belongsTo(User::class, 'support_user_id');
    }
}
