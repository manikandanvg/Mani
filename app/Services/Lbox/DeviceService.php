<?php

namespace App\Services\Lbox;

use App\Models\Device;
use Illuminate\Support\Carbon;

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

    /** 60-second heartbeat: telemetry in, device row updated. */
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
    }
}
