<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity on the platform (board 2026-10-31). Written only through
 * App\Services\Activity\ActivityLogger; read on System → Activity Log.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'details' => 'array',
    ];

    /** Category key => board label. Every log row carries exactly one. */
    public const CATEGORIES = [
        'sales' => 'Sales & billing',
        'contract' => 'Contracts & settlements',
        'gold_saving' => 'Gold Saving collections',
        'redemption' => 'Stock QR & redemptions',
        'commission' => 'Commissions & payouts',
        'wallet' => 'Wallet & withdrawals',
        'digi' => 'Digi Market',
        'stock' => 'Stock, purchases & transfers',
        'orders' => 'Order requests & customised orders',
        'network' => 'Distributors & network',
        'branch' => 'Branches & dealers',
        'master' => 'Master data & settings',
        'rates' => 'Live rates',
        'auth' => 'Logins & sessions',
        'otp' => 'OTP',
        'communication' => 'Push, inbox & WhatsApp',
        'app' => 'App requests',
        'support' => 'Support & tickets',
        'meetings' => 'Meetings & community',
        'payroll' => 'Attendance & payroll',
        'lbox' => 'L-BOX devices',
        'tasks' => 'Monthly tasks',
        'system' => 'System jobs & imports',
        'error' => 'Errors',
    ];

    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key] ?? ucfirst((string) $key);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
