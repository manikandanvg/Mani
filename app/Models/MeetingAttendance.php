<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live-meeting attendance row. source 'app' = the member tapped Join in the
 * app (identity certain); source 'zoom' = Zoom participant webhook (duration
 * authoritative, member matched when possible).
 */
class MeetingAttendance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'duration_min' => 'integer',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
