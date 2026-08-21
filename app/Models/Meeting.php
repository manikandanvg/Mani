<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A scheduled live meeting (Phase 6a). See migration 2026_06_14_170000.
 */
class Meeting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'duration_min' => 'integer',
        'is_published' => 'boolean',
    ];

    public function attendances()
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /** When the meeting is expected to end. */
    public function endsAt(): \Illuminate\Support\Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_min ?: 60);
    }

    /** 'upcoming' | 'live' | 'ended' relative to now. */
    public function status(): string
    {
        $now = now();
        if ($now->lt($this->scheduled_at)) {
            return 'upcoming';
        }

        return $now->lte($this->endsAt()) ? 'live' : 'ended';
    }
}
