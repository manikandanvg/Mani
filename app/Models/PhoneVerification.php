<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One missed-call number verification attempt (see MissedCallVerification). */
class PhoneVerification extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function member() { return $this->belongsTo(Member::class); }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
