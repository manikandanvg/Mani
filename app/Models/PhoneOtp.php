<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time password issued for mobile phone login. See App\Services\Auth\OtpService.
 */
class PhoneOtp extends Model
{
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
