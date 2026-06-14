<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in account (member or retail customer).
 */
class AccountController extends Controller
{
    /** GET /me — the authenticated identity's profile, tagged with its mode. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Member) {
            return response()->json([
                'mode' => 'distributor',
                'member' => new MemberResource($user->load(['branch', 'rank', 'wallet'])),
                'customer' => null,
            ]);
        }

        return response()->json([
            'mode' => 'customer',
            'member' => null,
            'customer' => new CustomerResource($user),
        ]);
    }

    /** POST /logout — revoke the current access token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
