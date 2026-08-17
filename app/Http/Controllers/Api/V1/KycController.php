<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KycSetting;
use App\Models\Member;
use App\Services\Aadhaar\ChecksumAadhaarVerifier;
use App\Services\Pan\PanVerifier;
use App\Services\Push\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Re-KYC from the app (item 18, board 2026-08-12).
 *
 * PAN goes through the DIGITAL verifier (same drivers the Sales screen uses).
 * Aadhaar is checksum-validated, then the card photo is uploaded for MANUAL
 * approval — the admin flips aadhaar_verified on the member, which already
 * push-notifies the member ("Aadhaar verified ✓") via the Member observer.
 */
class KycController extends Controller
{
    /** GET /member/kyc — current status + whether the Re-KYC gate applies. */
    public function status(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return response()->json([
            'rekyc_active' => KycSetting::rekycActive(),
            'rekyc_required' => KycSetting::rekycRequiredFor($member),
            // OTP mode (Verification Settings toggle): the app swaps the photo/manual
            // flow for the fully automated Aadhaar OTP e-KYC flow.
            'aadhaar_otp_mode' => KycSetting::aadhaarOtpEnabled(),
            'pan' => [
                'set' => filled($member->pan),
                'masked' => $member->pan ? substr($member->pan, 0, 2) . '*****' . substr($member->pan, -3) : null,
                'verified' => (bool) $member->pan_verified,
                'verified_name' => $member->pan_verified_name,
                'verified_at' => optional($member->pan_verified_at)->toIso8601String(),
            ],
            'aadhaar' => [
                'set' => filled($member->aadhaar),
                'masked' => $member->aadhaar ? 'XXXX XXXX ' . substr($member->aadhaar, -4) : null,
                'verified' => (bool) $member->aadhaar_verified,
                'doc_uploaded' => filled($member->aadhaar_doc_path),
                'verified_at' => optional($member->aadhaar_verified_at)->toIso8601String(),
            ],
        ]);
    }

    /** POST /member/kyc/pan — {pan, name?, dob?} → digital verification. */
    public function verifyPan(Request $request, PanVerifier $verifier): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'pan' => ['required', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
        ]);
        $pan = strtoupper($data['pan']);

        $result = $verifier->verify($pan, $member->name, optional($member->dob)->format('d/m/Y'));

        if (! ($result['valid'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'] ?? 'PAN could not be verified. Check the number and try again.',
            ], 422);
        }

        $member->update([
            'pan' => $pan,
            'pan_verified' => true,
            'pan_verified_name' => $result['registered_name'] ?? null,
            'pan_verified_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'registered_name' => $result['registered_name'] ?? null,
            'match' => $result['match'] ?? 'unknown',
            'message' => 'PAN verified successfully.',
        ]);
    }

    /**
     * POST /member/kyc/aadhaar — multipart {aadhaar, doc}. Checksum-validated,
     * document stored; admin approves manually under Network → Members.
     */
    public function submitAadhaar(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'aadhaar' => ['required', 'string'],
            'doc' => ['required', 'image', 'max:8192'],   // Aadhaar card photo ≤8 MB
        ]);

        $check = (new ChecksumAadhaarVerifier)->verify($data['aadhaar']);
        if (! $check['valid']) {
            return response()->json(['ok' => false, 'message' => $check['message']], 422);
        }

        $path = $request->file('doc')->store('kyc/aadhaar', 'public');

        $member->update([
            'aadhaar' => preg_replace('/\s+/', '', $data['aadhaar']),
            'aadhaar_doc_path' => $path,
            'aadhaar_verified' => false,   // pending the admin's manual review
            'aadhaar_verified_at' => null,
        ]);

        Notifier::admins(
            'KYC document submitted — ' . $member->member_code,
            ($member->name ?: $member->member_code) . ' uploaded an Aadhaar card for re-KYC. '
                . 'Review and approve under Network → Members.',
            url: '/admin/members',
            category: 'system',
        );

        return response()->json([
            'ok' => true,
            'message' => 'Aadhaar submitted. Head Office will verify your document shortly — you will get a notification once approved.',
        ]);
    }

    /**
     * POST /member/kyc/aadhaar/otp — {aadhaar}. OTP e-KYC step 1 (only when the
     * Strong-verification toggle is ON): UIDAI sends an OTP to the Aadhaar-linked
     * mobile; the ref_id comes back to the app for step 2.
     */
    /** Every send burns a paid gateway credit — hard server-side limits per member. */
    public const OTP_COOLDOWN_SECONDS = 60;

    public const OTP_DAILY_CAP = 5;

    public function sendAadhaarOtp(Request $request, \App\Services\Aadhaar\AadhaarVerifier $verifier): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($verifier->supportsOtp(), 422, 'OTP verification is not enabled. Contact Head Office.');

        $data = $request->validate(['aadhaar' => ['required', 'string']]);

        // Cooldown + daily cap are enforced HERE, not just in the app UI — the app's
        // 60s countdown is advisory; this is what actually protects the credit top-up.
        $coolKey = "kyc:aadhaar-otp:cool:{$member->id}";
        $until = (int) Cache::get($coolKey, 0);
        if ($until > now()->getTimestamp()) {
            return response()->json([
                'ok' => false,
                'retry_in' => $until - now()->getTimestamp(),
                'message' => 'Please wait before requesting another OTP — the previous one is still on its way (it can take 2–3 minutes).',
            ], 429);
        }

        $dayKey = "kyc:aadhaar-otp:day:{$member->id}:" . now()->toDateString();
        if ((int) Cache::get($dayKey, 0) >= self::OTP_DAILY_CAP) {
            return response()->json([
                'ok' => false,
                'message' => 'Daily OTP limit reached for this account. Please try again tomorrow or contact Head Office.',
            ], 429);
        }

        $res = $verifier->sendOtp($data['aadhaar']);

        if ($res['ok'] ?? false) {
            Cache::put($coolKey, now()->addSeconds(self::OTP_COOLDOWN_SECONDS)->getTimestamp(), self::OTP_COOLDOWN_SECONDS);
            Cache::add($dayKey, 0, now()->endOfDay());
            Cache::increment($dayKey);
        }

        return response()->json($res, ($res['ok'] ?? false) ? 200 : 422);
    }

    /**
     * POST /member/kyc/aadhaar/otp/verify — {aadhaar, ref_id, otp}. Step 2: a valid
     * OTP verifies the member INSTANTLY — no photo, no manual review.
     */
    public function verifyAadhaarOtp(Request $request, \App\Services\Aadhaar\AadhaarVerifier $verifier): JsonResponse
    {
        $member = $this->member($request);
        abort_unless($verifier->supportsOtp(), 422, 'OTP verification is not enabled. Contact Head Office.');

        $data = $request->validate([
            'aadhaar' => ['required', 'string'],
            'ref_id' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        $res = $verifier->verifyOtp($data['ref_id'], $data['otp']);

        if (! ($res['valid'] ?? false)) {
            return response()->json(['ok' => false, 'message' => $res['message'] ?? 'OTP verification failed.'], 422);
        }

        $member->update([
            'aadhaar' => preg_replace('/\s+/', '', $data['aadhaar']),
            'aadhaar_verified' => true,   // verified_at auto-stamped by the model hook
        ]);

        return response()->json([
            'ok' => true,
            'name' => $res['name'] ?? null,
            'message' => 'Aadhaar verified successfully' . (! empty($res['name']) ? ' as ' . $res['name'] : '') . '.',
        ]);
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }
}
