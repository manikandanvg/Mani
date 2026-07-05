<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * An L-BOX smart branch device (internal-only). Authenticates against the device API
 * with a Sanctum bearer token issued when its one-time pairing code is redeemed
 * (hence Authenticatable — Sanctum guards require it, same as Member/Customer).
 */
class Device extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            $device->uuid ??= (string) Str::uuid();
            $device->pairing_code ??= self::newPairingCode();
        });
    }

    public static function newPairingCode(): string
    {
        return strtoupper(Str::random(8));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(VoiceAnnouncement::class);
    }

    /** Heartbeats come every ~60s; three missed beats = offline. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }
}
