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
        'audience_ranks' => 'array',
    ];

    /**
     * Multi-select audience (board phase 2, 2026-08-28): the exact TBP rank depths
     * this meeting is for — e.g. [1, 4] = Taluk Admin AND State Admin. NULL / empty
     * = every distributor. Replaces the old "minimum rank & above" gate.
     */
    public function audienceDepths(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->audience_ranks ?? []))));
    }

    public function isForDepth(int $depth): bool
    {
        $depths = $this->audienceDepths();

        return $depths === [] || in_array($depth, $depths, true);
    }

    /** Meetings visible to a member of the given rank depth (untargeted ones included). */
    public function scopeForDepth($query, int $depth)
    {
        return $query->where(function ($q) use ($depth) {
            $q->whereNull('audience_ranks')
                ->orWhereJsonLength('audience_ranks', 0)
                ->orWhereJsonContains('audience_ranks', $depth);
        });
    }

    /** The L-BOX (arena box) an in-person meeting is held at — RFID taps there count as attendance. */
    public function device()
    {
        return $this->belongsTo(Device::class);
    }

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
