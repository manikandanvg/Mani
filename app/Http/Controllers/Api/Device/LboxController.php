<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Firmware;
use App\Models\VoiceAnnouncement;
use App\Services\Lbox\AnnouncementService;
use App\Services\Lbox\DeviceAttendanceService;
use App\Services\Lbox\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * L-BOX device API (/api/device/v1). The box authenticates with the Sanctum token it
 * received when its one-time pairing code was redeemed at first boot. Endpoints match
 * the LBOX OS firmware contract: register, heartbeat, attendance tap, voice queue, OTA.
 */
class LboxController extends Controller
{
    public function __construct(
        protected DeviceService $devices,
        protected AnnouncementService $announcements,
    ) {
    }

    /** POST register — public; auth = serial + one-time pairing code. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial_no' => ['required', 'string', 'max:40'],
            'pairing_code' => ['required', 'string', 'max:16'],
            'board_type' => ['nullable', 'in:lite,pro'],
            'firmware_version' => ['nullable', 'string', 'max:24'],
        ]);

        try {
            [$device, $token] = $this->devices->register($data['serial_no'], $data['pairing_code'], $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'token' => $token,
            'device' => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'branch' => $device->branch?->name,
            ],
        ], 201);
    }

    /** POST heartbeat — telemetry in; pending work counts out. */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:24'],
            'battery_pct' => ['nullable', 'integer', 'between:0,100'],
            'rssi' => ['nullable', 'integer', 'between:-150,0'],
            'uptime_s' => ['nullable', 'integer', 'min:0'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->devices->heartbeat($device, $data + ['ip' => $request->ip()]);

        $latest = Firmware::where('board_type', $device->board_type)->where('is_active', true)->first();

        return response()->json([
            'ok' => true,
            'pending_announcements' => $device->announcements()->where('status', 'pending')->count(),
            'ota_available' => $latest !== null
                && version_compare($latest->version, (string) $device->firmware_version, '>'),
        ]);
    }

    /** POST attendance — RFID tap. The box speaks/displays the returned message. */
    public function attendance(Request $request, DeviceAttendanceService $taps): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'tag_uid' => ['required', 'string', 'max:32'],
        ]);

        $result = $taps->tap($device, $data['tag_uid']);

        return response()->json(['ok' => true] + $result);
    }

    /** GET announcements — oldest pending lines (marked delivered). Poll every few seconds. */
    public function announcements(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $lines = $this->announcements->pull($device);

        return response()->json([
            'data' => $lines->map(fn (VoiceAnnouncement $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'message' => $a->message,
                'payload' => $a->payload,
            ])->values(),
        ]);
    }

    /** POST announcements/ack — after speaking. */
    public function ackAnnouncements(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        return response()->json(['ok' => true, 'acked' => $this->announcements->ack($device, $data['ids'])]);
    }

    /** GET ota/check — newer active firmware for this board, if any. */
    public function otaCheck(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $latest = Firmware::where('board_type', $device->board_type)->where('is_active', true)->first();

        if (! $latest || ! version_compare($latest->version, (string) $device->firmware_version, '>')) {
            return response()->json(['update' => null]);
        }

        return response()->json([
            'update' => [
                'version' => $latest->version,
                'sha256' => $latest->sha256,
                'size_bytes' => (int) $latest->size_bytes,
                'url' => url("/api/device/v1/ota/download/{$latest->id}"),
            ],
        ]);
    }

    /** GET ota/download/{firmware} — stream the binary (board must match). */
    public function otaDownload(Request $request, int $firmware)
    {
        $device = $this->device($request);

        $fw = Firmware::where('id', $firmware)->where('board_type', $device->board_type)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($fw->path), 404, 'Firmware binary missing.');

        return Storage::disk('local')->download($fw->path, "lbox-{$fw->board_type}-{$fw->version}.bin");
    }

    /** Resolve the authenticated Device, or 403 (member/customer tokens don't belong here). */
    protected function device(Request $request): Device
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 403, 'Device token required.');
        abort_if($device->status !== 'active', 403, 'Device is ' . $device->status . '.');

        return $device;
    }
}
