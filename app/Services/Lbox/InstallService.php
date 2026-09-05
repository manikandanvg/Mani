<?php

namespace App\Services\Lbox;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Member;
use App\Services\Tasks\TaskEngine;
use Illuminate\Support\Carbon;

/**
 * App-assisted L-BOX installation (board 2026-08-23 items 1–5).
 *
 * Flow: the installer (the member who runs the branch, or HQ) opens Install L-BOX in
 * the app → the phone finds the box over BLE and reads the 6-digit code off its screen
 * → the app calls start() to create/claim the device row and get a fresh pairing code
 * → the phone writes Wi-Fi + pairing code to the box over BLE → the box joins Wi-Fi and
 * redeems the code itself (LboxController::register) → the app polls status() until
 * the box has registered, then complete() anchors it with the phone's GPS (a Lite has
 * no GPS of its own).
 *
 * Boxes already online over 4G (Pro) get their Wi-Fi pushed in the heartbeat response
 * instead — setWifi() bumps wifi_updated_at, the firmware applies it once.
 */
class InstallService
{
    /** The branch this member may install boxes for (null = may not install). */
    public function branchFor(Member $member): ?Branch
    {
        return TaskEngine::branchRunBy($member);
    }

    public function isHq(?Branch $branch): bool
    {
        return $branch !== null && $branch->level === 'hq';
    }

    /** Boxes this installer can see: every box of their branch (HQ: every box). */
    public function devicesFor(Member $member)
    {
        $branch = $this->branchFor($member);
        if (! $branch) {
            return collect();
        }

        return Device::with('branch')
            ->when(! $this->isHq($branch), fn ($q) => $q->where('branch_id', $branch->id))
            ->orderBy('name')->get();
    }

    /**
     * Create or claim the device row for the box in front of the installer and issue a
     * pairing code for it. Returns everything the phone has to write to the box.
     *
     * @return array{device: Device, pairing_code: string, api_url: string, wake_host: string|null}
     */
    public function start(Member $member, array $data): array
    {
        $branch = $this->branchFor($member);
        if (! $branch) {
            throw new \RuntimeException('You do not run a branch — only branch in-charges and HQ can install a box.');
        }

        $serial = strtoupper(trim($data['serial_no']));
        $device = Device::where('serial_no', $serial)->first();

        if ($device) {
            if (in_array($device->status, ['suspended', 'retired'], true)) {
                throw new \RuntimeException("This box is {$device->status}. Ask Head Office.");
            }
            if ($device->branch_id && (int) $device->branch_id !== (int) $branch->id && ! $this->isHq($branch)) {
                throw new \RuntimeException('This box belongs to another branch. Ask Head Office to move it.');
            }
        } else {
            $device = new Device([
                'name' => $data['name'] ?? ($branch->name . ' L-BOX'),
                'serial_no' => $serial,
                'board_type' => $data['board_type'] ?? 'lite',
                'status' => 'provisioned',
            ]);
        }

        $device->fill(array_filter([
            'branch_id' => $device->branch_id ?: $branch->id,
            'board_type' => $data['board_type'] ?? null,
            'mac' => isset($data['mac']) ? strtoupper($data['mac']) : null,
            'name' => $data['name'] ?? null,
        ], fn ($v) => $v !== null));
        $device->installed_by_member_id = $member->id;
        // Fresh one-time code — any earlier code stops working, same as the admin action.
        $device->pairing_code = Device::newPairingCode();
        $device->save();

        return [
            'device' => $device->fresh('branch'),
            'pairing_code' => $device->pairing_code,
            'api_url' => $this->deviceApiUrl(),
            'wake_host' => config('lbox.wake_host') ?: null,
        ];
    }

    /** Where the box should talk to — HQ's fleet URL if set, else this server. */
    public function deviceApiUrl(): string
    {
        return config('lbox.device_api_url') ?: url('/api/device/v1');
    }

    /** What the app polls after writing the box: has it reached the cloud yet? */
    public function status(Device $device): array
    {
        $device->refresh();

        return [
            'id' => $device->id,
            'serial_no' => $device->serial_no,
            'name' => $device->name,
            'board_type' => $device->board_type,
            'status' => $device->status,
            'registered' => $device->registered_at !== null && $device->pairing_code === null,
            'registered_at' => $device->registered_at?->toIso8601String(),
            'online' => $device->isOnline(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'firmware_version' => $device->firmware_version,
            'ip' => $device->ip,
            'anchored' => $device->anchor_lat !== null,
            'is_displaced' => (bool) $device->is_displaced,
            'wifi_ssid' => $device->wifi_ssid,
            'installed_at' => $device->installed_at?->toIso8601String(),
            'branch' => $device->branch ? ['id' => $device->branch->id, 'name' => $device->branch->name] : null,
        ];
    }

    /**
     * The phone's GPS becomes the box's anchor (and the branch's map pin when it has
     * none) — the same first-fix rule DeviceService applies to a Pro's own GNSS. A box
     * that already has an anchor keeps it; HQ's Re-anchor action is the way to move one.
     */
    public function complete(Member $member, Device $device, array $data): array
    {
        $this->assertMayManage($member, $device);

        if (isset($data['lat'], $data['lng']) && $device->anchor_lat === null) {
            $device->update([
                'anchor_lat' => $data['lat'],
                'anchor_lng' => $data['lng'],
                'anchored_at' => Carbon::now(),
                'is_displaced' => false,
            ]);
            $branch = $device->branch;
            if ($branch && (blank($branch->latitude) || (float) $branch->latitude === 0.0)) {
                $branch->update(['latitude' => $data['lat'], 'longitude' => $data['lng']]);
            }
        }

        $device->update(array_filter([
            'wifi_ssid' => $data['wifi_ssid'] ?? null,
            'installed_at' => Carbon::now(),
            'installed_by_member_id' => $member->id,
        ], fn ($v) => $v !== null));

        return $this->status($device);
    }

    /** Wi-Fi for a box that is already online (over 4G) — applied on its next heartbeat. */
    public function setWifi(Member $member, Device $device, string $ssid, string $pass): array
    {
        $this->assertMayManage($member, $device);

        $device->update([
            'wifi_ssid' => $ssid,
            'wifi_pass' => $pass,
            'wifi_updated_at' => Carbon::now(),
        ]);

        return $this->status($device);
    }

    public function assertMayManage(Member $member, Device $device): void
    {
        $branch = $this->branchFor($member);
        if (! $branch) {
            abort(403, 'You do not run a branch.');
        }
        if (! $this->isHq($branch) && (int) $device->branch_id !== (int) $branch->id) {
            abort(403, 'This box belongs to another branch.');
        }
    }
}
