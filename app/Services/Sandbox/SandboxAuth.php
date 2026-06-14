<?php

namespace App\Services\Sandbox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Shared Sandbox (sandbox.co.in) authentication — one access token cached for ~23h and
 * reused by every Sandbox product (PAN, Aadhaar OKYC). Credentials live under
 * config('services.pan') since they belong to the same Sandbox account.
 */
class SandboxAuth
{
    public const CACHE_KEY = 'sandbox_access_token';

    public static function token(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(23), function () {
            $cfg = config('services.pan');
            $base = rtrim((string) ($cfg['endpoint'] ?? 'https://api.sandbox.co.in'), '/');

            $res = Http::withOptions(['verify' => app_ca()])->timeout(20)
                ->withHeaders([
                    'x-api-key' => (string) ($cfg['key'] ?? ''),
                    'x-api-secret' => (string) ($cfg['secret'] ?? ''),
                    'x-api-version' => $cfg['version'] ?? '1.0',
                ])->post($base . '/authenticate')
                ->throw()->json();

            return (string) ($res['access_token'] ?? $res['data']['access_token'] ?? '');
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Common headers for an authenticated Sandbox call. */
    public static function headers(): array
    {
        $cfg = config('services.pan');

        return [
            'Authorization' => self::token(),
            'x-api-key' => (string) ($cfg['key'] ?? ''),
            'x-api-version' => $cfg['version'] ?? '1.0',
            'Content-Type' => 'application/json',
        ];
    }

    public static function baseUrl(): string
    {
        return rtrim((string) (config('services.pan.endpoint') ?? 'https://api.sandbox.co.in'), '/');
    }
}
