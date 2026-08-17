<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Member;
use App\Models\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My Profile (item 21) + the mobile device registry (items 16/17b).
 *
 * Profile: basic info (email, dob, address, city, pincode, UPI) editable from the
 * app, plus a photo that uploads to the server. Device registry: each app install
 * reports its stable device uid, platform and biometric-unlock enrollment so HQ
 * can see every phone a distributor signs in from.
 */
class ProfileController extends Controller
{
    /** GET /member/profile — everything the profile card shows/edits. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->member($request))]);
    }

    /** POST /member/profile — update the editable basics. */
    public function update(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'dob' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'upi' => ['nullable', 'string', 'max:80'],
        ]);

        $member->fill($data)->save();

        return response()->json(['ok' => true, 'data' => $this->present($member->refresh())]);
    }

    /** POST /member/profile/photo — multipart {photo}; stored on the public disk. */
    public function photo(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $request->validate([
            'photo' => ['required', 'image', 'max:6144'],   // ≤6 MB
        ]);

        $path = $request->file('photo')->store('members', 'public');
        $member->update(['photo_path' => $path]);

        return response()->json([
            'ok' => true,
            'photo_url' => ProductResource::imageUrl($path),
        ]);
    }

    /**
     * POST /device-registry — upsert this install. {device_uid, device_name?,
     * platform?, biometric_enabled?, app_version?}. Called at login and whenever
     * biometric unlock is toggled.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'device_uid' => ['required', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios'],
            'biometric_enabled' => ['nullable', 'boolean'],
            'app_version' => ['nullable', 'string', 'max:24'],
        ]);

        $device = MobileDevice::updateOrCreate(
            ['device_uid' => $data['device_uid']],
            array_filter([
                'member_id' => $member->id,     // device follows the signed-in distributor
                'phone' => $member->phone,
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                'app_version' => $data['app_version'] ?? null,
            ], fn ($v) => $v !== null) + [
                'biometric_enabled' => (bool) ($data['biometric_enabled'] ?? false),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['ok' => true, 'device_id' => $device->id]);
    }

    protected function present(Member $member): array
    {
        return [
            'member_code' => $member->member_code,
            'name' => $member->name,
            'phone' => $member->phone,
            'email' => $member->email,
            'dob' => optional($member->dob)->toDateString(),
            'address' => $member->address,
            'city' => $member->city,
            'pincode' => $member->pincode,
            'upi' => $member->upi,
            'photo_url' => ProductResource::imageUrl($member->photo_path),
            'kyc' => [
                'pan_set' => filled($member->pan),
                'pan_verified' => (bool) $member->pan_verified,
                'aadhaar_set' => filled($member->aadhaar),
                'aadhaar_verified' => (bool) $member->aadhaar_verified,
                'aadhaar_doc_uploaded' => filled($member->aadhaar_doc_path),
                'rekyc_required' => \App\Models\KycSetting::rekycRequiredFor($member),
            ],
        ];
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        return $user;
    }
}
