<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued voice line for an L-BOX device: pending → delivered (device fetched it)
 * → acked (device finished speaking it). Payments, attendance echoes, HQ tests.
 */
class VoiceAnnouncement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
