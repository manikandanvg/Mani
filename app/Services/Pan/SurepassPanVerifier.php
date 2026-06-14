<?php

namespace App\Services\Pan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Surepass / Sandbox / Zoop-style PAN name-match driver. Sends PAN + name, expects a
 * match verdict + score. Endpoint/token come from config('services.pan'). Falls back
 * to a safe "unknown" result on any transport error (never blocks billing silently —
 * the screen decides what to do with 'unknown').
 */
class SurepassPanVerifier implements PanVerifier
{
    public function verify(string $pan, ?string $name = null, ?string $dob = null): array
    {
        $pan = strtoupper(trim($pan));
        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            return ['valid' => false, 'registered_name' => null, 'match' => 'none', 'score' => 0, 'message' => 'Invalid PAN format'];
        }

        $cfg = config('services.pan');
        try {
            $res = Http::withOptions(['verify' => app_ca()])
                ->withToken($cfg['token'] ?? '')
                ->timeout(8)
                ->post($cfg['endpoint'] ?? '', ['id_number' => $pan, 'name' => $name])
                ->throw()
                ->json();

            // provider field names vary; map defensively
            $data = $res['data'] ?? $res;
            $registered = $data['full_name'] ?? $data['name'] ?? null;
            $score = (float) ($data['name_match_score'] ?? $data['score'] ?? 0);
            $match = $data['name_match'] ?? ($score >= 0.8 ? 'exact' : ($score >= 0.5 ? 'partial' : 'none'));

            return [
                'valid' => (bool) ($data['valid'] ?? true),
                'registered_name' => $registered,
                'match' => is_string($match) ? strtolower($match) : ($match ? 'exact' : 'none'),
                'score' => $score,
                'message' => $data['message'] ?? 'PAN verified',
            ];
        } catch (\Throwable $e) {
            Log::warning('PAN verify failed: ' . $e->getMessage());

            return ['valid' => false, 'registered_name' => null, 'match' => 'unknown', 'score' => 0, 'message' => 'Verification service unavailable'];
        }
    }
}
