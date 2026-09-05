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
        'volume_updated_at' => 'datetime',
        'wifi_pass' => 'encrypted',
        'wifi_updated_at' => 'datetime',
        'installed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            $device->uuid ??= (string) Str::uuid();
            $device->pairing_code ??= self::newPairingCode();
        });

        // volume_updated_at is the change-version the firmware compares against:
        // bumping it makes every box apply the new HQ level exactly once.
        static::saving(function (Device $device) {
            if ($device->isDirty('volume_level')) {
                $device->volume_updated_at = now();
            }
            // Same idea for pushed Wi-Fi: edited anywhere → every box takes it once.
            if ($device->isDirty(['wifi_ssid', 'wifi_pass'])) {
                $device->wifi_updated_at = now();
            }
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

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'installed_by_member_id');
    }

    /**
     * Wi-Fi HQ/the installer pushed for this box (heartbeat payload). `ver` is the
     * change-version the firmware compares against so it applies each push once.
     */
    public function wifiPayload(): ?array
    {
        // Nothing to push until BOTH halves exist: complete() records the SSID the box was
        // installed on without a password, and pushing that would knock the box off Wi-Fi.
        if (! $this->wifi_ssid || $this->wifi_pass === null) {
            return null;
        }

        return [
            'ssid' => $this->wifi_ssid,
            'pass' => (string) $this->wifi_pass,
            'ver' => $this->wifi_updated_at?->getTimestamp() ?? 1,
        ];
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
