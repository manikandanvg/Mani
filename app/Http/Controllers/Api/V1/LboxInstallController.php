<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Member;
use App\Services\Lbox\InstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App → Install L-BOX (Branch tools). The member-side half of the BLE install flow;
 * the box's own half is /api/device/v1 (LboxController). See InstallService.
 */
class LboxInstallController extends Controller
{
    public function __construct(protected InstallService $installs)
    {
    }

    /** GET /member/lbox/devices — the boxes of the branch I run (HQ: all). */
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $branch = $this->installs->branchFor($member);

        return response()->json([
            'can_install' => $branch !== null,
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'level' => $branch->level] : null,
            'devices' => $this->installs->devicesFor($member)->map(fn (Device $d) => $this->installs->status($d))->values(),
        ]);
    }

    /** POST /member/lbox/install/start — create/claim the device row, get a pairing code. */
    public function start(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $data = $request->validate([
            'serial_no' => ['required', 'string', 'max:40'],
            'board_type' => ['nullable', 'in:lite,pro,standard'],
            'mac' => ['nullable', 'string', 'max:17'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $r = $this->installs->start($member, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'device' => $this->installs->status($r['device']),
            'pairing_code' => $r['pairing_code'],
            'api_url' => $r['api_url'],
            'wake_host' => $r['wake_host'],
        ], 201);
    }

    /** GET /member/lbox/devices/{device}/status — poll until the box has registered. */
    public function status(Request $request, Device $device): JsonResponse
    {
        $this->installs->assertMayManage($this->member($request), $device);

        return response()->json($this->installs->status($device));
    }

    /** POST /member/lbox/devices/{device}/complete — phone GPS anchor + install stamp. */
    public function complete(Request $request, Device $device): JsonResponse
    {
        $member = $this->member($request);
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'wifi_ssid' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->installs->complete($member, $device, $data));
    }

    /** POST /member/lbox/devices/{device}/wifi — push Wi-Fi to a box that is online over 4G. */
    public function wifi(Request $request, Device $device): JsonResponse
    {
        $member = $this->member($request);
        $data = $request->validate([
            'ssid' => ['required', 'string', 'max:64'],
            'pass' => ['present', 'string', 'max:64'],
        ]);

        return response()->json($this->installs->setWifi($member, $device, $data['ssid'], $data['pass']));
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'Distributor account required.');

        return $user;
    }
}
