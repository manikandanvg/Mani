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

    /**
     * Tie a Zoom participant back to a member. App-minted joins put the member
     * code after the name ("Priya · LJW01"), so the code is the reliable key; a
     * Zoom-account e-mail and an exact name are the fallbacks. Shared by the
     * participant webhooks and zoom:sync-attendance (2026-08-25).
     */
    public static function matchMember(string $name, string $email): ?int
    {
        if ($name !== '' && preg_match('/(?:·|\(|-)\s*([A-Za-z0-9]{3,20})\)?\s*$/u', $name, $m)) {
            $id = Member::whereRaw('UPPER(member_code) = ?', [strtoupper($m[1])])->value('id');
            if ($id) {
                return $id;
            }
        }
        $email = strtolower(trim($email));
        if ($email !== '' && ($id = Member::whereRaw('LOWER(email) = ?', [$email])->value('id'))) {
            return $id;
        }

        return $name !== '' ? Member::where('name', $name)->value('id') : null;
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
