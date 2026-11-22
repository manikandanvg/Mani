<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A member removed from the live tables (user 2026-11-22). The profile, the settled statement
 * and — in deleted_member_records — every live row that belonged to the member live here only.
 */
class DeletedMember extends Model
{
    protected $guarded = [];

    protected $casts = [
        'profile' => 'array',
        'statement' => 'array',
        'joined_on' => 'date',
        'removed_at' => 'datetime',
        'issued_total' => 'decimal:2',
        'recovered_total' => 'decimal:2',
        'charged_total' => 'decimal:2',
    ];

    public function records()
    {
        return $this->hasMany(DeletedMemberRecord::class);
    }

    /** A deleted member's code is never issued again. */
    public static function codeTaken(string $code): bool
    {
        return static::ready() && static::where('member_code', $code)->exists();
    }

    /** Highest member id ever archived — the code generators count on from max(live, deleted). */
    public static function maxMemberId(): int
    {
        return static::ready() ? (int) static::max('member_id') : 0;
    }

    protected static ?bool $ready = null;

    protected static function ready(): bool
    {
        return static::$ready ??= Schema::hasTable('deleted_members');
    }
}
