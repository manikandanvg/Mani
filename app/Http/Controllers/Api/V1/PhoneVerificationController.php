<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Services\Phone\MissedCallVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Missed-call number verification for the trusted-device registration (board 2026-11-02).
 * Public (the user is not signed in yet), rate-limited; the number must belong to an active
 * distributor. The app shows the dial number, opens the dialer, then polls status().
 */
class PhoneVerificationController extends Controller
{
    public function start(Request $request, MissedCallVerification $svc): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'purpose' => ['nullable', 'in:registration,number_change,device'],
            'device_uid' => ['nullable', 'string', 'max:80'],
        ]);
        $row = $svc->start($data['phone'], $data['purpose'] ?? 'registration', $data['device_uid'] ?? null, $request->ip());

        return response()->json($this->payload($row));
    }

    public function status(Request $request, PhoneVerification $verification, MissedCallVerification $svc): JsonResponse
    {
        // the id alone is guessable; the caller must also know the number it started with
        abort_unless(MissedCallVerification::digits($request->query('phone')) === $verification->phone, 404);

        return response()->json($this->payload($svc->status($verification)));
    }

    protected function payload(PhoneVerification $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'dial_number' => $row->dial_number,
            'expires_at' => $row->expires_at?->toIso8601String(),
            'verified_at' => $row->verified_at?->toIso8601String(),
            'instruction' => 'Give a missed call to ' . $row->dial_number . ' from ' . $row->phone . '. It cuts after one ring and costs nothing.',
        ];
    }
}
