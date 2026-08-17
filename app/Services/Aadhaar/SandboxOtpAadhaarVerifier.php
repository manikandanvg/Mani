<?php

namespace App\Services\Aadhaar;

use App\Services\Sandbox\SandboxAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Strong Aadhaar verification via Sandbox UIDAI OKYC OTP:
 *   1) sendOtp()   -> POST /kyc/aadhaar/okyc/otp           (OTP to linked mobile, returns ref_id)
 *   2) verifyOtp() -> POST /kyc/aadhaar/okyc/otp/verify    (ref_id + otp -> e-KYC name/dob)
 *
 * Uses the shared Sandbox account (config services.pan creds via SandboxAuth). A paid
 * call that needs Aadhaar OKYC enabled + credits on the account. Never throws into the
 * caller — returns a safe result with a message on any error.
 */
class SandboxOtpAadhaarVerifier implements AadhaarVerifier
{
    public function supportsOtp(): bool
    {
        return true;
    }

    /** Offline format/checksum still available as a pre-check before sending an OTP. */
    public function verify(string $aadhaar): array
    {
        return (new ChecksumAadhaarVerifier)->verify($aadhaar);
    }

    public function sendOtp(string $aadhaar): array
    {
        $num = preg_replace('/\s+/', '', $aadhaar) ?? '';
        if (! preg_match('/^[2-9][0-9]{11}$/', $num)) {
            return ['ok' => false, 'ref_id' => null, 'message' => 'Enter a valid 12-digit Aadhaar number first.'];
        }

        try {
            $payload = [
                '@entity' => 'in.co.sandbox.kyc.aadhaar.okyc.otp.request',
                'aadhaar_number' => $num,
                'consent' => 'Y',
                'reason' => 'KYC verification for jewellery purchase / contract.',
            ];
            $res = $this->post('/kyc/aadhaar/okyc/otp', $payload);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $refId = $data['ref_id'] ?? $data['reference_id'] ?? null;

            if ($res->successful() && $refId) {
                return ['ok' => true, 'ref_id' => (string) $refId, 'message' => $data['message'] ?? 'OTP sent to the Aadhaar-linked mobile.'];
            }

            $this->logGateway('send', $res->status(), $body);

            return ['ok' => false, 'ref_id' => null, 'message' => $this->friendly($body['message'] ?? ($data['message'] ?? 'Could not send OTP'))];
        } catch (\Throwable $e) {
            Log::warning('Aadhaar OTP send failed: ' . $e->getMessage());

            return ['ok' => false, 'ref_id' => null, 'message' => 'Aadhaar service unavailable.'];
        }
    }

    public function verifyOtp(string $refId, string $otp): array
    {
        if (blank($refId) || blank($otp)) {
            return ['valid' => false, 'name' => null, 'message' => 'Missing OTP / reference.'];
        }

        try {
            $payload = [
                '@entity' => 'in.co.sandbox.kyc.aadhaar.okyc.request',
                'reference_id' => (string) $refId,
                'otp' => (string) $otp,
            ];
            $res = $this->post('/kyc/aadhaar/okyc/otp/verify', $payload);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;

            $status = strtoupper((string) ($data['status'] ?? ''));
            $valid = $res->successful() && ($status === 'VALID' || ! empty($data['name']));

            if (! $valid) {
                $this->logGateway('verify', $res->status(), $body);
            }

            return [
                'valid' => (bool) $valid,
                'name' => $data['name'] ?? null,
                'dob' => $data['date_of_birth'] ?? null,
                'message' => $valid ? 'Aadhaar verified via OTP' : $this->friendly($body['message'] ?? ($data['message'] ?? 'OTP verification failed')),
            ];
        } catch (\Throwable $e) {
            Log::warning('Aadhaar OTP verify failed: ' . $e->getMessage());

            return ['valid' => false, 'name' => null, 'message' => 'Aadhaar service unavailable.'];
        }
    }

    protected function post(string $path, array $payload)
    {
        $res = Http::withOptions(['verify' => app_ca()])->timeout(25)
            ->withHeaders(SandboxAuth::headers())
            ->post(SandboxAuth::baseUrl() . $path, $payload);

        if ($res->status() === 401) {
            SandboxAuth::forget();
            $res = Http::withOptions(['verify' => app_ca()])->timeout(25)
                ->withHeaders(SandboxAuth::headers())
                ->post(SandboxAuth::baseUrl() . $path, $payload);
        }

        return $res;
    }

    /**
     * Gateway strings are written for developers, not distributors. UIDAI's OKYC
     * source drops out regularly (peak hours especially) and surfaces as
     * "Source Unavailable" / 502 / 503 — that is a wait-and-retry, NOT a wrong
     * OTP, so the member must be told to keep the same OTP screen open.
     */
    protected function friendly(string $msg): string
    {
        if (stripos($msg, 'credit') !== false) {
            return 'Aadhaar gateway has no credits — top up your Sandbox account (console.sandbox.co.in).';
        }

        foreach (['source unavailable', 'source not available', 'upstream', 'gateway time', 'timed out', 'temporarily unavailable'] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return 'The UIDAI Aadhaar service is not responding right now. This is not a problem with your OTP — wait a minute and press Verify OTP again.';
            }
        }

        if (stripos($msg, 'otp') !== false && stripos($msg, 'invalid') !== false) {
            return 'That OTP is not correct. Check the SMS and re-enter it.';
        }

        if (stripos($msg, 'expire') !== false) {
            return 'The OTP has expired. Tap Resend OTP to get a new one.';
        }

        return $msg;
    }

    /** Keep the gateway's own transaction_id — Sandbox support asks for it first. */
    protected function logGateway(string $step, int $status, array $body): void
    {
        Log::warning('Aadhaar OKYC ' . $step . ' failed', [
            'http' => $status,
            'code' => $body['code'] ?? null,
            'message' => $body['message'] ?? null,
            'transaction_id' => $body['transaction_id'] ?? null,
        ]);
    }
}
