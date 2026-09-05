<?php

namespace App\Services\Lbox;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * L-BOX device lifecycle: HQ creates the device row (pairing code auto-generated),
 * the box redeems the code ONCE at first boot for its Sanctum bearer token, then
 * heartbeats every ~60s. Re-pairing after a factory reset needs HQ to regenerate
 * the code — a lost/stolen box cannot re-join on its own.
 */
class DeviceService
{
    /** Redeem a pairing code → [device, plain-text bearer token]. */
    public function register(string $serialNo, string $pairingCode, array $info = []): array
    {
        $device = Device::where('serial_no', $serialNo)->first();

        if (! $device || ! $device->pairing_code || ! hash_equals($device->pairing_code, strtoupper(trim($pairingCode)))) {
            throw new \RuntimeException('Unknown serial or invalid pairing code.');
        }
        if (in_array($device->status, ['suspended', 'retired'], true)) {
            throw new \RuntimeException('This device is ' . $device->status . '.');
        }

        $device->tokens()->delete();   // old tokens die with re-pairing
        $token = $device->createToken('lbox', ['device'])->plainTextToken;

        $device->update([
            'status' => 'active',
            'pairing_code' => null,
            'registered_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'firmware_version' => $info['firmware_version'] ?? $device->firmware_version,
            'board_type' => $info['board_type'] ?? $device->board_type,
            'mac' => isset($info['mac']) ? strtoupper($info['mac']) : $device->mac,
        ]);

        return [$device->fresh(), $token];
    }

    /** Issue a fresh one-time pairing code (Filament action). */
    public function regeneratePairingCode(Device $device): string
    {
        $code = Device::newPairingCode();
        $device->update(['pairing_code' => $code]);

        return $code;
    }

    /** 60-second heartbeat: telemetry in, device row updated, anchor rules applied. */
    public function heartbeat(Device $device, array $data): void
    {
        $device->update(array_filter([
            'firmware_version' => $data['firmware_version'] ?? null,
            'battery_pct' => $data['battery_pct'] ?? null,
            'rssi' => $data['rssi'] ?? null,
            'uptime_s' => $data['uptime_s'] ?? null,
            'ip' => $data['ip'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ], fn ($v) => $v !== null) + ['last_seen_at' => Carbon::now()]);

        if (isset($data['lat'], $data['lng'])) {
            $this->applyAnchor($device->fresh(), (float) $data['lat'], (float) $data['lng']);
        }
    }

    /**
     * Installation anchor (user rule): the FIRST fix after pairing is saved as the
     * box's home position AND becomes the branch's map location. A later fix beyond
     * the anchor radius marks the box DISPLACED — the branch is treated offline and
     * withdrawals at it are blocked — and clears again if the box returns.
     */
    protected function applyAnchor(Device $device, float $lat, float $lng): void
    {
        if ($device->anchor_lat === null) {
            $device->update([
                'anchor_lat' => $lat,
                'anchor_lng' => $lng,
                'anchored_at' => Carbon::now(),
                'is_displaced' => false,
            ]);

            // "fetch the gps location and save as branch location"
            $branch = $device->branch;
            if ($branch && (blank($branch->latitude) || (float) $branch->latitude === 0.0)) {
                $branch->update(['latitude' => $lat, 'longitude' => $lng]);
            }

            return;
        }

        $distance = $this->haversineMeters(
            $lat, $lng, (float) $device->anchor_lat, (float) $device->anchor_lng,
        );
        $displaced = $distance > (int) config('lbox.anchor_radius_m', 150);

        if ($displaced !== (bool) $device->is_displaced) {
            $device->update(['is_displaced' => $displaced]);
            Log::warning(sprintf(
                '[lbox] %s %s (%.0fm from anchor)',
                $device->serial_no, $displaced ? 'DISPLACED — branch treated offline' : 'back at its anchor', $distance,
            ));
        }
    }

    /** HQ approved a genuine relocation: forget the anchor — the next fix re-anchors. */
    public function reAnchor(Device $device): void
    {
        $device->update([
            'anchor_lat' => null, 'anchor_lng' => null,
            'anchored_at' => null, 'is_displaced' => false,
        ]);
    }

    protected function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
