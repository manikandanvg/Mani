<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time password issued for mobile phone login. See App\Services\Auth\OtpService.
 */
class PhoneOtp extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'otp';

    public const ACTIVITY_IGNORE = ['attempts'];

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
