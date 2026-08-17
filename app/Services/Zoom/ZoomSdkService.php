<?php

namespace App\Services\Zoom;

/**
 * Zoom Meeting SDK auth (in-app joining, 2026-08-12). Signs the SDK JWT with the
 * Client Secret server-side — the secret never reaches the app; the app/web page
 * only ever sees a short-lived signature scoped to ONE meeting number.
 * Config: services.zoom.{sdk_client_id, sdk_client_secret} (.env ZOOM_SDK_*).
 */
class ZoomSdkService
{
    public function configured(): bool
    {
        return filled(config('services.zoom.sdk_client_id')) && filled(config('services.zoom.sdk_client_secret'));
    }

    public function clientId(): string
    {
        return (string) config('services.zoom.sdk_client_id');
    }

    /**
     * HS256 Meeting SDK signature for a meeting number. role 0 = attendee.
     * Valid for 2 hours (Zoom requires 1800s ≤ exp − iat ≤ 48h).
     */
    public function signature(string $meetingNumber, int $role = 0): string
    {
        $iat = time() - 30;
        $exp = $iat + 7200;

        $header = $this->b64([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]);
        $payload = $this->b64([
            'appKey' => $this->clientId(),
            'sdkKey' => $this->clientId(),   // legacy field name, still accepted
            'mn' => $meetingNumber,
            'role' => $role,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp,
        ]);

        $sig = hash_hmac('sha256', "{$header}.{$payload}", (string) config('services.zoom.sdk_client_secret'), true);

        return "{$header}.{$payload}." . $this->b64url($sig);
    }

    protected function b64(array $data): string
    {
        return $this->b64url((string) json_encode($data));
    }

    protected function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
