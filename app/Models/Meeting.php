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

    /**
     * Distinct people who attended: members counted once even when they have
     * both an app-join row and a Zoom row; unmatched Zoom participants count
     * once per Zoom participant id (or name).
     */
    public function uniqueAttendeeCount(): int
    {
        return (int) $this->attendances()
            ->selectRaw("count(distinct member_id) + count(distinct case when member_id is null then coalesce(zoom_participant_id, participant_name) end) as n")
            ->value('n');
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
