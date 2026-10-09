<?php

namespace App\Services\Push;

use App\Models\PushSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Native Apple Push Notification service sender (board 2026-09-21: iPhones push
 * over APNs directly, not via Firebase).
 *
 * Auth is token-based: a JWT signed ES256 with the .p8 auth key from the Apple
 * Developer portal (Key ID + Team ID), cached ~50 min — Apple requires a fresh
 * token at least every 60 min and rejects one refreshed inside 20 min. Delivery is
 * one HTTP/2 POST per device token to /3/device/{token} with the bundle id as the
 * `apns-topic`. Same contract as PushSender: never throws into the caller, reports
 * dead tokens as `stale` so the channel prunes them.
 *
 * Host: api.push.apple.com for App Store / TestFlight builds; the sandbox host for
 * Xcode development builds (APNS_SANDBOX=true). A token registered from one
 * environment is rejected by the other with BadDeviceToken.
 */
class ApnsSender
{
    private const HOST_PRODUCTION = 'https://api.push.apple.com';
    private const HOST_SANDBOX = 'https://api.sandbox.push.apple.com';

    /** Apple `reason` strings that mean the device token is gone for good. */
    private const DEAD_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic', 'ExpiredToken'];

    protected function creds(): array
    {
        return PushSetting::apns();
    }

    /** Whether a usable auth key is configured. */
    public function enabled(): bool
    {
        $c = $this->creds();

        return (bool) ($c['enabled'] ?? false)
            && filled($c['key_id'] ?? null)
            && filled($c['team_id'] ?? null)
            && filled($c['bundle_id'] ?? null)
            && filled($c['private_key'] ?? null);
    }

    public function host(): string
    {
        return ($this->creds()['sandbox'] ?? false) ? self::HOST_SANDBOX : self::HOST_PRODUCTION;
    }

    /**
     * Send one alert to many APNs device tokens (hex strings from the app).
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data   custom keys placed beside `aps`
     * @return array{ok: bool, sent: int, failed: int, stale: array<int, string>, skipped?: bool}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'stale' => []];
        }
        if (! $this->enabled()) {
            return ['ok' => false, 'skipped' => true, 'sent' => 0, 'failed' => 0, 'stale' => []];
        }

        $jwt = $this->providerToken();
        if ($jwt === null) {
            return ['ok' => false, 'skipped' => true, 'sent' => 0, 'failed' => 0, 'stale' => []];
        }

        $payload = array_merge(
            // `aps` must win over any custom key of the same name.
            array_diff_key($data, ['aps' => true]),
            ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']],
        );
        $topic = $this->creds()['bundle_id'];
        $host = $this->host();

        $sent = 0;
        $failed = 0;
        $stale = [];

        foreach ($tokens as $token) {
            try {
                $res = Http::withHeaders([
                    'authorization' => 'bearer ' . $jwt,
                    'apns-topic' => $topic,
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                ])
                    ->withOptions(['verify' => app_ca(), 'version' => 2.0])   // APNs is HTTP/2 only
                    ->timeout(15)
                    ->post("{$host}/3/device/{$token}", $payload);

                if ($res->successful()) {
                    $sent++;
                    continue;
                }

                $failed++;
                $reason = (string) data_get($res->json(), 'reason');
                if ($res->status() === 410 || in_array($reason, self::DEAD_REASONS, true)) {
                    $stale[] = $token;
                }
                if ($reason === 'ExpiredProviderToken' || $reason === 'InvalidProviderToken') {
                    Cache::forget('apns.provider_token');
                }
                Log::warning('APNs send failed', ['status' => $res->status(), 'reason' => $reason]);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('APNs send error: ' . $e->getMessage());
            }
        }

        return ['ok' => $failed === 0, 'sent' => $sent, 'failed' => $failed, 'stale' => $stale];
    }

    /** ES256 provider token, cached ~50 min. Null if the .p8 key is unusable. */
    protected function providerToken(): ?string
    {
        return Cache::remember('apns.provider_token', 3000, function () {
            $c = $this->creds();
            $header = $this->b64(json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
            $claims = $this->b64(json_encode(['iss' => $c['team_id'], 'iat' => time()]));
            $signingInput = $header . '.' . $claims;

            $der = '';
            $key = str_replace('\n', "\n", (string) $c['private_key']);
            if (! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
                Log::warning('APNs JWT signing failed — check the .p8 auth key in Push Notification Settings.');

                return null;
            }
            $raw = $this->derToRawSignature($der);
            if ($raw === null) {
                Log::warning('APNs JWT signing failed — the auth key is not a P-256 EC key.');

                return null;
            }

            return $signingInput . '.' . $this->b64($raw);
        });
    }

    /**
     * OpenSSL emits an ECDSA signature DER-encoded (SEQUENCE of two INTEGERs);
     * JWS ES256 wants the raw 64-byte r||s concatenation.
     */
    protected function derToRawSignature(string $der): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            return null;
        }
        $pos = 2;
        if (ord($der[1]) & 0x80) {                 // long-form length
            $pos += ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (! isset($der[$pos]) || ord($der[$pos]) !== 0x02) {
                return null;
            }
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $int = ltrim($int, "\x00");
            if (strlen($int) > 32) {
                return null;
            }
            $parts[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    protected function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
