<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Push-gateway credentials (single row, id 1) — FCM + APNs under one roof
 * (board 2026-08-11). Field precedence: DB value first, .env/config fallback —
 * identical pattern to WhatsappSetting so ops learn it once.
 */
class PushSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fcm_enabled' => 'boolean',
        'apns_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'fcm_enabled' => (bool) config('services.fcm.enabled', true),
            'fcm_project_id' => config('services.fcm.project_id'),
            'fcm_client_email' => config('services.fcm.client_email'),
            'fcm_private_key' => config('services.fcm.private_key'),
        ]);
    }

    /** Effective FCM credentials: DB row first, config/.env as fallback. */
    public static function fcm(): array
    {
        $row = static::query()->find(1);
        $cfg = config('services.fcm', []);

        return [
            'enabled' => $row ? (bool) $row->fcm_enabled : (bool) ($cfg['enabled'] ?? false),
            'project_id' => $row?->fcm_project_id ?: ($cfg['project_id'] ?? null),
            'client_email' => $row?->fcm_client_email ?: ($cfg['client_email'] ?? null),
            'private_key' => $row?->fcm_private_key ?: ($cfg['private_key'] ?? null),
        ];
    }

    /**
     * Effective native-APNs credentials (board 2026-09-21): DB row first, config/.env
     * as fallback. `sandbox` is env-only — it is a build-type switch, not a credential.
     */
    public static function apns(): array
    {
        $row = static::query()->find(1);
        $cfg = config('services.apns', []);

        return [
            'enabled' => $row ? (bool) $row->apns_enabled : (bool) ($cfg['enabled'] ?? false),
            'key_id' => $row?->apns_key_id ?: ($cfg['key_id'] ?? null),
            'team_id' => $row?->apns_team_id ?: ($cfg['team_id'] ?? null),
            'bundle_id' => $row?->apns_bundle_id ?: ($cfg['bundle_id'] ?? null),
            'private_key' => $row?->apns_private_key ?: ($cfg['private_key'] ?? null),
            'sandbox' => (bool) ($cfg['sandbox'] ?? false),
        ];
    }
}
