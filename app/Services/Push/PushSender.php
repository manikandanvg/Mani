<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) sender for app push (Phase 4b).
 *
 * Config-gated: until a service account is configured (config/services.fcm), every
 * call is a graceful no-op — so the inbox (database channel) keeps working and push
 * simply "lights up" once credentials land, with no code change. Like WhatsappSender,
 * it never throws into the caller; a push failure must not break the dispatching action.
 *
 * Auth: mints a short-lived OAuth2 access token by signing a JWT (RS256) with the
 * service-account private key and exchanging it at Google's token endpoint. The token
 * is cached for its lifetime so we don't re-mint on every send.
 */
class PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Effective FCM service account: the Push Notification Settings row first,
     * .env/config as the fallback (board 2026-08-11 — admin-editable like WhatsApp).
     */
    protected function creds(): array
    {
        return \App\Models\PushSetting::fcm();
    }

    /** Whether a usable service account is configured. */
    public function enabled(): bool
    {
        $c = $this->creds();

        return (bool) ($c['enabled'] ?? false)
            && filled($c['project_id'] ?? null)
            && filled($c['client_email'] ?? null)
            && filled($c['private_key'] ?? null);
    }

    /**
     * Send one notification to many device tokens. Returns a per-token result summary.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data   string-valued data payload (FCM requires strings)
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

        $access = $this->accessToken();
        if ($access === null) {
            return ['ok' => false, 'skipped' => true, 'sent' => 0, 'failed' => 0, 'stale' => []];
        }

        $project = $this->creds()['project_id'];
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";
        $stringData = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $data);

        $sent = 0;
        $failed = 0;
        $stale = [];

        foreach ($tokens as $token) {
            try {
                $res = Http::withToken($access)
                    ->withOptions(['verify' => app_ca()])
                    ->timeout(15)
                    ->post($endpoint, [
                        'message' => [
                            'token' => $token,
                            'notification' => ['title' => $title, 'body' => $body],
                            'data' => $stringData,
                        ],
                    ]);

                if ($res->successful()) {
                    $sent++;
                    continue;
                }

                $failed++;
                // 404 UNREGISTERED / 400 INVALID_ARGUMENT → the token is dead; caller prunes it.
                $status = (string) data_get($res->json(), 'error.status');
                if ($res->status() === 404 || $status === 'UNREGISTERED' || $status === 'NOT_FOUND') {
                    $stale[] = $token;
                }
                Log::warning('FCM send failed', ['status' => $res->status(), 'body' => $res->json()]);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('FCM send error: ' . $e->getMessage());
            }
        }

        return ['ok' => $failed === 0, 'sent' => $sent, 'failed' => $failed, 'stale' => $stale];
    }

    /** OAuth2 access token for FCM, cached for ~55 min. Null on failure. */
    protected function accessToken(): ?string
    {
        return Cache::remember('fcm.access_token', 3300, function () {
            $now = time();
            $email = $this->creds()['client_email'];

            $jwt = $this->signJwt([
                'iss' => $email,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]);
            if ($jwt === null) {
                return null;
            }

            try {
                $res = Http::asForm()->withOptions(['verify' => app_ca()])->timeout(15)->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                return $res->successful() ? $res->json('access_token') : null;
            } catch (\Throwable $e) {
                Log::warning('FCM token mint error: ' . $e->getMessage());

                return null;
            }
        });
    }

    /** Sign a service-account JWT (RS256). Null if the private key is unusable. */
    protected function signJwt(array $claims): ?string
    {
        $key = str_replace('\n', "\n", (string) $this->creds()['private_key']);
        $segments = [
            $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM JWT signing failed — check FCM_PRIVATE_KEY.');

            return null;
        }

        return $signingInput . '.' . $this->b64($signature);
    }

    protected function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
