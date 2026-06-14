<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Push-token registration (Phase 4b). The app registers its device token after sign-in
 * (and on token refresh), and unregisters on sign-out. Works for member and customer
 * tokens alike — the row is owned polymorphically by whoever is authenticated.
 */
class DeviceTokenController extends Controller
{
    /** POST /device-tokens — register/refresh this device for the signed-in identity. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'provider' => ['nullable', 'in:fcm,expo'],
        ]);

        $user = $request->user();

        // A device token is globally unique; if it moves to a new identity, re-own it.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'owner_type' => $user->getMorphClass(),
                'owner_id' => $user->getKey(),
                'platform' => $data['platform'] ?? 'android',
                'provider' => $data['provider'] ?? 'fcm',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true, 'id' => $device->id], 201);
    }

    /** DELETE /device-tokens — unregister a device (sign-out / disable push). */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $user = $request->user();

        DeviceToken::where('token', $data['token'])
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->delete();

        return response()->json(['ok' => true]);
    }
}
