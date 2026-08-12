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

    /**
     * GET /me/accounts — every member account registered under this login's
     * phone number (board 2026-08-11: one holder may run several accounts and
     * must be able to swap between them like the old web-view app).
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return response()->json([
            'data' => $this->sharedPhoneAccounts($user)->map(fn (Member $m) => [
                'id' => $m->id,
                'member_code' => $m->member_code,
                'name' => $m->name,
                'status' => $m->status,
                'branch' => $m->branch?->name,
                'current' => $m->id === $user->id,
            ])->values(),
        ]);
    }

    /**
     * POST /me/switch {member_id} — swap the session to a sibling account.
     * Phone ownership was proven at OTP login, so switching is allowed only
     * between accounts sharing that same phone; the old token is revoked.
     */
    public function switchAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        $data = $request->validate(['member_id' => ['required', 'integer']]);

        $target = $this->sharedPhoneAccounts($user)->firstWhere('id', (int) $data['member_id']);
        abort_unless($target, 404, 'That account is not linked to your phone number.');

        $device = $user->currentAccessToken()?->name ?? 'mobile';
        $token = $target->createToken($device)->plainTextToken;
        $user->currentAccessToken()?->delete();

        return response()->json([
            'token' => $token,
            'mode' => 'distributor',
            'member' => new MemberResource($target->load(['branch', 'rank', 'wallet'])),
            'customer' => null,
        ]);
    }

    /** Members sharing this member's phone (last-10-digit match, self included). */
    protected function sharedPhoneAccounts(Member $user)
    {
        $local = substr(preg_replace('/\D+/', '', (string) $user->phone), -10);

        // A too-short phone would turn the LIKE into a match-everything — lock to self.
        if (strlen($local) < 8) {
            return Member::with('branch')->whereKey($user->id)->get();
        }

        return Member::with('branch')
            ->where('phone', 'like', '%' . $local)
            ->orderBy('id')
            ->get();
    }
}
