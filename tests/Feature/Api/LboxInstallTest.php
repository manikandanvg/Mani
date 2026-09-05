<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * App-assisted L-BOX install (board 2026-08-23 items 1–5): the branch in-charge
 * claims a box from the app, the box redeems the issued pairing code itself, the
 * phone's GPS anchors it, and Wi-Fi pushes ride the heartbeat.
 */
class LboxInstallTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Branch $other;

    protected Member $incharge;

    protected Member $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Rajapalayam', 'country' => 'IN', 'is_active' => true, 'level' => 'taluk']);
        $this->other = Branch::create(['name' => 'Madurai', 'country' => 'IN', 'is_active' => true, 'level' => 'taluk']);
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);

        $this->incharge = Member::create([
            'member_code' => 'LJI01', 'name' => 'In-charge', 'phone' => '9000000101',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
            'branch_id' => $this->branch->id,
        ]);
        // Running a branch = a panel login carrying the member code + branch.
        User::create([
            'name' => 'In-charge', 'email' => 'incharge@test.local', 'password' => bcrypt('secret123'),
            'member_code' => 'LJI01', 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);

        $this->plain = Member::create([
            'member_code' => 'LJP01', 'name' => 'Plain', 'phone' => '9000000102',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    public function test_only_a_branch_incharge_can_install(): void
    {
        Sanctum::actingAs($this->plain, ['*']);
        $this->getJson('/api/v1/member/lbox/devices')->assertOk()->assertJsonPath('can_install', false);
        $this->postJson('/api/v1/member/lbox/install/start', ['serial_no' => 'LBX-NEW-1'])->assertStatus(422);

        Sanctum::actingAs($this->incharge, ['*']);
        $this->getJson('/api/v1/member/lbox/devices')->assertOk()
            ->assertJsonPath('can_install', true)
            ->assertJsonPath('branch.id', $this->branch->id);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('member.runs_branch.id', $this->branch->id);
    }

    public function test_start_creates_the_box_for_my_branch_and_the_box_redeems_that_code(): void
    {
        Sanctum::actingAs($this->incharge, ['*']);

        $res = $this->postJson('/api/v1/member/lbox/install/start', [
            'serial_no' => 'lbx-a1b2c3', 'board_type' => 'lite', 'mac' => 'aa:bb:cc:a1:b2:c3',
        ])->assertStatus(201);

        $code = $res->json('pairing_code');
        $this->assertSame(8, strlen($code));
        $this->assertStringEndsWith('/api/device/v1', $res->json('api_url'));
        $this->assertSame('LBX-A1B2C3', $res->json('device.serial_no'));
        $this->assertFalse($res->json('device.registered'));

        $device = Device::where('serial_no', 'LBX-A1B2C3')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $device->branch_id);
        $this->assertSame($this->incharge->id, (int) $device->installed_by_member_id);
        $this->assertSame('AA:BB:CC:A1:B2:C3', $device->mac);

        // The box itself (no member token) redeems the code — the app never sees a bearer.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/device/v1/register', [
            'serial_no' => 'LBX-A1B2C3', 'pairing_code' => $code, 'board_type' => 'lite', 'firmware_version' => '1.1.0',
        ])->assertStatus(201);

        Sanctum::actingAs($this->incharge, ['*']);
        $this->getJson("/api/v1/member/lbox/devices/{$device->id}/status")->assertOk()
            ->assertJsonPath('registered', true)
            ->assertJsonPath('status', 'active');

        // Starting again on a paired box just re-issues a code (re-install / new Wi-Fi at site).
        $again = $this->postJson('/api/v1/member/lbox/install/start', ['serial_no' => 'LBX-A1B2C3'])->assertStatus(201);
        $this->assertNotSame($code, $again->json('pairing_code'));
        $this->assertSame(1, Device::where('serial_no', 'LBX-A1B2C3')->count());
    }

    public function test_a_box_of_another_branch_is_refused_unless_hq(): void
    {
        $box = Device::create(['name' => 'Madurai box', 'serial_no' => 'LBX-MDU-1', 'branch_id' => $this->other->id]);

        Sanctum::actingAs($this->incharge, ['*']);
        $this->postJson('/api/v1/member/lbox/install/start', ['serial_no' => 'LBX-MDU-1'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'This box belongs to another branch. Ask Head Office to move it.']);
        $this->getJson("/api/v1/member/lbox/devices/{$box->id}/status")->assertStatus(403);

        // HQ may claim any box.
        $this->branch->update(['level' => 'hq']);
        $this->postJson('/api/v1/member/lbox/install/start', ['serial_no' => 'LBX-MDU-1'])->assertStatus(201);
        $this->assertSame($this->other->id, (int) $box->fresh()->branch_id);   // HQ does not steal it
    }

    public function test_complete_anchors_the_box_with_the_phone_gps_and_pins_the_branch(): void
    {
        Sanctum::actingAs($this->incharge, ['*']);
        $box = Device::create(['name' => 'Box', 'serial_no' => 'LBX-RJP-1', 'branch_id' => $this->branch->id]);

        $this->postJson("/api/v1/member/lbox/devices/{$box->id}/complete", [
            'lat' => 9.4533, 'lng' => 77.5563, 'wifi_ssid' => 'ShopWifi',
        ])->assertOk()->assertJsonPath('anchored', true)->assertJsonPath('wifi_ssid', 'ShopWifi');

        $box->refresh();
        $this->assertEqualsWithDelta(9.4533, (float) $box->anchor_lat, 0.00001);
        $this->assertNotNull($box->installed_at);
        $this->assertEqualsWithDelta(77.5563, (float) $this->branch->fresh()->longitude, 0.00001);

        // A second complete never moves an existing anchor (HQ's Re-anchor does that).
        $this->postJson("/api/v1/member/lbox/devices/{$box->id}/complete", ['lat' => 10.0, 'lng' => 78.0])->assertOk();
        $this->assertEqualsWithDelta(9.4533, (float) $box->fresh()->anchor_lat, 0.00001);
    }

    public function test_wifi_push_rides_the_heartbeat_once_per_change(): void
    {
        $box = Device::create(['name' => 'Box', 'serial_no' => 'LBX-RJP-2', 'branch_id' => $this->branch->id, 'board_type' => 'pro', 'status' => 'active']);

        Sanctum::actingAs($box, ['device']);
        $this->postJson('/api/device/v1/heartbeat', ['firmware_version' => '1.3.0'])->assertOk()->assertJsonPath('wifi', null);

        Sanctum::actingAs($this->incharge, ['*']);
        $this->postJson("/api/v1/member/lbox/devices/{$box->id}/wifi", ['ssid' => 'ShopWifi', 'pass' => 'gold1234'])->assertOk();
        $this->assertSame('gold1234', $box->fresh()->wifi_pass);   // encrypted at rest, readable via the cast
        $this->assertNotSame('gold1234', $box->fresh()->getRawOriginal('wifi_pass'));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($box->fresh(), ['device']);
        $beat = $this->postJson('/api/device/v1/heartbeat', ['firmware_version' => '1.3.0'])->assertOk();
        $this->assertSame('ShopWifi', $beat->json('wifi.ssid'));
        $this->assertSame('gold1234', $beat->json('wifi.pass'));
        $ver = $beat->json('wifi.ver');
        $this->assertGreaterThan(0, $ver);

        // Editing the SSID from the panel bumps the version so the box applies it again.
        $box->fresh()->update(['wifi_ssid' => 'ShopWifi5G']);
        $this->travel(2)->seconds();
        $box->fresh()->update(['wifi_pass' => 'newpass99']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($box->fresh(), ['device']);   // the guard caches the model instance
        $beat2 = $this->postJson('/api/device/v1/heartbeat', ['firmware_version' => '1.3.0'])->assertOk();
        $this->assertGreaterThan($ver, $beat2->json('wifi.ver'));
        $this->assertSame('ShopWifi5G', $beat2->json('wifi.ssid'));
    }
}
