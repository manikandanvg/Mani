<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A push-notification registration for one device of one identity (Member/Customer).
 * See migration 2026_06_14_130000. Owned polymorphically; `token` is unique per device.
 */
class DeviceToken extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
