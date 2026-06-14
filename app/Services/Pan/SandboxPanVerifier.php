<?php

namespace App\Services\Pan;

use App\Services\Sandbox\SandboxAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sandbox (sandbox.co.in) PAN verification driver. Two-step:
 *   1) POST /authenticate (x-api-key + x-api-secret) -> access_token (cached ~23h)
 *   2) POST /kyc/pan/verify with the name (and DOB if known) -> name_as_per_pan_match
 *
 * Sandbox returns a boolean name match (not a score); we map true -> 'exact',
 * false -> 'none'. Config: services.pan.{key,secret,endpoint,version}. Re-auths once
 * on a 401. Never throws into the caller — returns a safe 'unknown' on transport error.
 */
class SandboxPanVerifier implements PanVerifier
{
    public function verify(string $pan, ?string $name = null, ?string $dob = null): array
    {
        $pan = strtoupper(trim($pan));
        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            return ['valid' => false, 'registered_name' => null, 'match' => 'none', 'score' => 0, 'message' => 'Invalid PAN format'];
        }
        // Sandbox's PAN name-match endpoint requires DOB — fail clearly before the API.
        if (blank($dob)) {
            return ['valid' => false, 'registered_name' => null, 'match' => 'unknown', 'score' => 0,
                'message' => 'Enter the date of birth to verify this PAN.'];
        }

        try {
            $res = $this->callVerify($pan, $name, $dob);

            // one transparent retry only if the cached token expired (401)
            if ($res->status() === 401) {
                SandboxAuth::forget();
                $res = $this->callVerify($pan, $name, $dob);
            }

            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;

            if (! $res->successful() || empty($data['status'] ?? null)) {
                $msg = $body['message'] ?? ($data['remarks'] ?? 'Verification failed');
                // make the most common account issue actionable
                if (stripos($msg, 'credit') !== false) {
                    $msg = 'PAN gateway has no credits — top up your Sandbox account (console.sandbox.co.in).';
                }

                return ['valid' => false, 'registered_name' => null, 'match' => 'unknown', 'score' => 0, 'message' => $msg];
            }

            $status = strtoupper((string) ($data['status'] ?? ''));
            $valid = in_array($status, ['VALID', 'EXISTING AND VALID', 'ACTIVE'], true) || ($data['status'] === true);
            $nameMatch = $data['name_as_per_pan_match'] ?? $data['name_match'] ?? null;

            // boolean name match -> match verdict (only meaningful when a name was sent)
            $match = is_null($name)
                ? 'unknown'
                : ($nameMatch === true ? 'exact' : ($nameMatch === false ? 'none' : 'unknown'));

            return [
                'valid' => (bool) $valid,
                'registered_name' => $data['name_as_per_pan'] ?? $data['full_name'] ?? null,
                'match' => $match,
                'score' => $nameMatch === true ? 1.0 : 0.0,
                'message' => $data['remarks'] ?? ($valid ? 'PAN verified' : 'PAN not valid'),
                'dob_match' => $data['date_of_birth_match'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Sandbox PAN verify failed: ' . $e->getMessage());

            return ['valid' => false, 'registered_name' => null, 'match' => 'unknown', 'score' => 0, 'message' => 'Verification service unavailable'];
        }
    }

    protected function callVerify(string $pan, ?string $name, ?string $dob)
    {
        $payload = array_filter([
            '@entity' => 'in.co.sandbox.kyc.pan_verification.request',
            'pan' => $pan,
            'name_as_per_pan' => $name,
            'date_of_birth' => $this->formatDob($dob),
            'consent' => 'Y',
            'reason' => 'KYC verification for jewellery purchase / contract.',
        ], fn ($v) => $v !== null && $v !== '');

        return Http::withOptions(['verify' => app_ca()])->timeout(20)
            ->withHeaders(SandboxAuth::headers())
            ->post(SandboxAuth::baseUrl() . '/kyc/pan/verify', $payload);
    }

    /** Sandbox expects DD/MM/YYYY. Accepts Y-m-d or anything strtotime understands. */
    protected function formatDob(?string $dob): ?string
    {
        if (blank($dob)) {
            return null;
        }
        $ts = strtotime($dob);

        return $ts ? date('d/m/Y', $ts) : null;
    }
}
