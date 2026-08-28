<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HQ memo (board phase 2, 2026-08-28 — Community → "Memo", formerly "Messages"):
 * a note broadcast to EVERY app-registered distributor as a push notification plus
 * an inbox entry the moment it is saved. sent_count = members reached.
 */
class Memo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'sent_count' => 'integer',
    ];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
