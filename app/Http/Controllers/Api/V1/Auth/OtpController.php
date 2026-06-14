<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MemberResource;
use App\Models\Customer;
use App\Models\Member;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone-OTP login for the mobile app. Two steps:
 *   POST /auth/otp/request  → issue + deliver a code
 *   POST /auth/otp/verify   → check the code, issue a Sanctum token
 *
 * Verify resolves the phone to an MLM `member` if one exists (mode=distributor);
 * otherwise it find-or-creates a retail `customer` (mode=customer) — this is the
 * public shopper "make an account at checkout" path.
 */
class OtpController extends Controller
{
    public function __construct(protected OtpService $otp) {}

    /** Step 1 — send an OTP to the phone. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
        ]);

        $result = $this->otp->issue($data['phone'], 'login');

        return response()->json(array_filter([
            'message' => $result['sent']
                ? 'OTP sent.'
                : 'OTP generated but delivery failed — try again or contact support.',
            'sent' => $result['sent'],
            'expires_in' => OtpService::CODE_TTL_MINUTES * 60,
            // Only present outside production, to ease local/dev testing.
            'debug_code' => $result['debug_code'],
        ], fn ($v) => $v !== null));
    }

    /** Step 2 — verify the OTP and return an API token + profile. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
            'code' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        if (! $this->otp->verify($data['phone'], $data['code'], 'login')) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $device = $data['device_name'] ?? 'mobile';

        // A registered MLM member logs in as a distributor...
        if ($member = $this->resolveMember($data['phone'])) {
            $member->load(['branch', 'rank', 'wallet']);

            return response()->json([
                'token' => $member->createToken($device)->plainTextToken,
                'mode' => 'distributor',
                'member' => new MemberResource($member),
                'customer' => null,
            ]);
        }

        // ...otherwise the phone becomes (or resumes) a retail customer account.
        $customer = Customer::firstOrCreate(['phone' => $this->otp->normalize($data['phone'])]);

        return response()->json([
            'token' => $customer->createToken($device)->plainTextToken,
            'mode' => 'customer',
            'member' => null,
            'customer' => new CustomerResource($customer),
        ]);
    }

    /** Resolve a member by phone, tolerant of country-code/formatting differences. */
    protected function resolveMember(string $phone): ?Member
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $local = substr($digits, -10);

        return Member::where(function ($q) use ($phone, $digits, $local) {
            $q->where('phone', $phone)
                ->orWhere('phone', $digits)
                ->orWhere('phone', $local)
                ->orWhere('phone', 'like', '%' . $local);
        })->first();
    }
}
