<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Firmware;
use App\Models\Member;
use App\Models\Rank;
use App\Services\Lbox\AnnouncementService;
use App\Services\Payroll\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L-BOX device API: pairing-code registration, heartbeat telemetry, RFID attendance
 * taps into the payroll ledger, the voice announcement queue, and OTA checks.
 */
class LboxDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Rajapalayam', 'country' => 'IN', 'is_active' => true]);
        $this->device = Device::create([
            'name' => 'Counter Box',
            'serial_no' => 'LBX-LITE-0001',
            'board_type' => 'lite',
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_pairing_code_registration_issues_token_and_burns_the_code(): void
    {
        $code = $this->device->pairing_code;
        $this->assertNotNull($code);

        // wrong code refused
        $this->postJson('/api/device/v1/register', [
            'serial_no' => 'LBX-LITE-0001', 'pairing_code' => 'WRONG123',
        ])->assertStatus(422);

        $res = $this->postJson('/api/device/v1/register', [
            'serial_no' => 'LBX-LITE-0001', 'pairing_code' => $code, 'firmware_version' => '1.0.0',
        ])->assertStatus(201);

        $token = $res->json('token');
        $this->assertNotEmpty($token);

        $fresh = $this->device->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->pairing_code);          // one-time
        $this->assertSame('1.0.0', $fresh->firmware_version);

        // the burnt code cannot register again
        $this->postJson('/api/device/v1/register', [
            'serial_no' => 'LBX-LITE-0001', 'pairing_code' => $code,
        ])->assertStatus(422);

        // the issued bearer token works on an authed endpoint
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/device/v1/heartbeat', ['battery_pct' => 90])
            ->assertOk();
    }

    public function test_heartbeat_updates_telemetry_and_member_tokens_are_rejected(): void
    {
        $this->activateDevice();
        Sanctum::actingAs($this->device, ['*']);

        $this->postJson('/api/device/v1/heartbeat', [
            'firmware_version' => '1.0.1', 'battery_pct' => 76, 'rssi' => -61, 'uptime_s' => 3600,
        ])->assertOk()->assertJsonPath('ota_available', false);

        $fresh = $this->device->fresh();
        $this->assertSame(76, (int) $fresh->battery_pct);
        $this->assertSame(-61, (int) $fresh->rssi);
        $this->assertSame('1.0.1', $fresh->firmware_version);
        $this->assertTrue($fresh->isOnline());

        // a member token must not reach device endpoints
        $rank = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $member = Member::create([
            'member_code' => 'LBX1', 'name' => 'Person', 'phone' => '9000000070',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
        Sanctum::actingAs($member, ['*']);
        $this->postJson('/api/device/v1/heartbeat')->assertStatus(403);
    }

    public function test_rfid_tap_checks_in_then_out_into_the_payroll_ledger(): void
    {
        $this->activateDevice();
        $employee = $this->makeEmployee('TAG001122');

        Sanctum::actingAs($this->device, ['*']);

        // unknown card
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'NOPE'])
            ->assertOk()->assertJsonPath('result', 'unknown_tag');

        // first tap = check-in
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'tag001122'])
            ->assertOk()->assertJsonPath('result', 'checked_in');

        $record = AttendanceRecord::where('employee_profile_id', $employee->employeeProfile->id)->firstOrFail();
        $this->assertSame('device', $record->source);
        $this->assertSame('present', $record->status);
        $this->assertNotNull($record->check_in_at);

        // immediate second tap = duplicate window
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAG001122'])
            ->assertOk()->assertJsonPath('result', 'duplicate');

        // after the window, the tap checks out
        $record->update(['check_in_at' => now()->subHours(8)]);
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAG001122'])
            ->assertOk()->assertJsonPath('result', 'checked_out');

        $this->assertNotNull($record->fresh()->check_out_at);

        // day complete
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAG001122'])
            ->assertOk()->assertJsonPath('result', 'day_complete');
    }

    public function test_announcement_queue_delivers_in_order_and_acks(): void
    {
        $this->activateDevice();
        $svc = app(AnnouncementService::class);

        // queueForBranch hits every ACTIVE box of the branch
        $count = $svc->queueForBranch($this->branch->id, 'payment', 'Payment received. Rupees 5,000.', ['amount' => 5000]);
        $this->assertSame(1, $count);
        $svc->queue($this->device, 'test', 'Second line');

        Sanctum::actingAs($this->device, ['*']);

        $res = $this->getJson('/api/device/v1/announcements')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Payment received. Rupees 5,000.', $res->json('data.0.message'));

        // pulled lines are delivered — a re-poll returns nothing
        $this->getJson('/api/device/v1/announcements')->assertOk()->assertJsonCount(0, 'data');

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->postJson('/api/device/v1/announcements/ack', ['ids' => $ids])
            ->assertOk()->assertJsonPath('acked', 2);

        $this->assertSame(2, $this->device->announcements()->where('status', 'acked')->count());
    }

    public function test_ota_check_offers_only_newer_active_firmware_and_downloads_it(): void
    {
        Storage::fake('local');
        $this->activateDevice(['firmware_version' => '1.0.0']);

        Storage::disk('local')->put('firmware/lite-101.bin', 'BINARY-CONTENT');
        $fw = Firmware::create([
            'board_type' => 'lite', 'version' => '1.0.1', 'path' => 'firmware/lite-101.bin',
            'sha256' => hash('sha256', 'BINARY-CONTENT'), 'size_bytes' => 14, 'is_active' => false,
        ]);

        Sanctum::actingAs($this->device, ['*']);

        // inactive firmware is not offered
        $this->getJson('/api/device/v1/ota/check')->assertOk()->assertJsonPath('update', null);

        $fw->update(['is_active' => true]);
        $res = $this->getJson('/api/device/v1/ota/check')->assertOk();
        $this->assertSame('1.0.1', $res->json('update.version'));
        $this->assertSame(hash('sha256', 'BINARY-CONTENT'), $res->json('update.sha256'));

        $this->get('/api/device/v1/ota/download/' . $fw->id, ['Accept' => 'application/json'])
            ->assertOk();

        // a device already on the active version gets nothing
        $this->device->update(['firmware_version' => '1.0.1']);
        $this->getJson('/api/device/v1/ota/check')->assertOk()->assertJsonPath('update', null);
    }

    public function test_first_gps_fix_anchors_the_box_and_saves_the_branch_location_and_movement_displaces(): void
    {
        $this->activateDevice();
        Sanctum::actingAs($this->device, ['*']);

        // installation fix → anchor + branch location saved
        $this->postJson('/api/device/v1/heartbeat', ['lat' => 9.4535168, 'lng' => 77.5521164])->assertOk();
        $fresh = $this->device->fresh();
        $this->assertSame(9.4535168, (float) $fresh->anchor_lat);
        $this->assertFalse((bool) $fresh->is_displaced);
        $this->assertSame(9.4535168, (float) $this->branch->fresh()->latitude);

        // ~1.1km away → DISPLACED (branch treated offline)
        $this->postJson('/api/device/v1/heartbeat', ['lat' => 9.4635168, 'lng' => 77.5521164])->assertOk();
        $this->assertTrue((bool) $this->device->fresh()->is_displaced);

        // back home → clears automatically
        $this->postJson('/api/device/v1/heartbeat', ['lat' => 9.4535200, 'lng' => 77.5521100])->assertOk();
        $this->assertFalse((bool) $this->device->fresh()->is_displaced);

        // HQ-approved relocation: re-anchor forgets home; the next fix re-anchors
        app(\App\Services\Lbox\DeviceService::class)->reAnchor($this->device->fresh());
        $this->postJson('/api/device/v1/heartbeat', ['lat' => 10.0, 'lng' => 78.0])->assertOk();
        $moved = $this->device->fresh();
        $this->assertSame(10.0, (float) $moved->anchor_lat);
        $this->assertFalse((bool) $moved->is_displaced);
    }

    public function test_rfid_taps_write_the_branch_opening_and_closing_register(): void
    {
        $this->activateDevice();
        $employee = $this->makeEmployee('TAGBR01');
        Sanctum::actingAs($this->device, ['*']);

        $this->assertFalse(\App\Models\BranchAttendance::isOpenToday($this->branch->id));

        // morning tap → branch OPENS (the box celebrates this tap: green ring + OLED)
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAGBR01'])->assertOk()
            ->assertJsonPath('branch_opened', true);
        $day = \App\Models\BranchAttendance::firstOrFail();
        $this->assertTrue(\App\Models\BranchAttendance::isOpenToday($this->branch->id));
        $this->assertNotNull($day->opened_at);
        $this->assertSame($employee->employeeProfile->id, (int) $day->opened_by);
        $this->assertNull($day->closed_at);

        // the tap recorded WHICH box it happened at
        $this->assertSame($this->device->id, (int) AttendanceRecord::firstOrFail()->device_id);

        // a second employee's morning tap checks in WITHOUT re-opening the branch
        $this->makeEmployee('TAGBR02');
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAGBR02'])->assertOk()
            ->assertJsonPath('result', 'checked_in')
            ->assertJsonPath('branch_opened', false);

        // evening check-out tap → closing time stamped
        AttendanceRecord::query()->where('device_id', $this->device->id)
            ->update(['check_in_at' => now()->subHours(9)]);
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'TAGBR01'])->assertOk()
            ->assertJsonPath('result', 'checked_out');
        $day->refresh();
        $this->assertNotNull($day->closed_at);
        $this->assertSame($employee->employeeProfile->id, (int) $day->closed_by);
    }

    public function test_branch_card_opens_and_closes_and_unknown_cards_report_their_uid(): void
    {
        $this->activateDevice();
        Sanctum::actingAs($this->device, ['*']);

        // Unknown card → the box shows its UID so HQ can register it
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'cafe01'])->assertOk()
            ->assertJsonPath('result', 'unknown_tag')
            ->assertJsonPath('tag_uid', 'CAFE01');

        // HQ registers that card as THE branch card (lost card = replace this value)
        $this->branch->update(['rfid_tag' => 'CAFE01']);
        $this->device->unsetRelation('branch');   // acting instance caches the relation

        // Morning tap → branch OPENS (no employee attendance involved)
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'cafe01'])->assertOk()
            ->assertJsonPath('result', 'branch_opened')
            ->assertJsonPath('branch_opened', true);
        $this->assertTrue(\App\Models\BranchAttendance::isOpenToday($this->branch->id));

        // Same card again within the duplicate window → no accidental close
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'CAFE01'])->assertOk()
            ->assertJsonPath('result', 'duplicate');

        // Evening tap → closing time stamped
        \App\Models\BranchAttendance::query()->update(['opened_at' => now()->subHours(9)]);
        $this->postJson('/api/device/v1/attendance', ['tag_uid' => 'CAFE01'])->assertOk()
            ->assertJsonPath('result', 'branch_closed');
        $this->assertNotNull(\App\Models\BranchAttendance::firstOrFail()->closed_at);
    }

    public function test_hq_volume_rides_the_heartbeat_and_voice_commands_carry_an_action(): void
    {
        $this->activateDevice();
        Sanctum::actingAs($this->device, ['*']);

        // No HQ volume set yet → null level, version 0 (box never applies)
        $this->postJson('/api/device/v1/heartbeat', [])->assertOk()
            ->assertJsonPath('volume', null)
            ->assertJsonPath('volume_ver', 0);

        // HQ picks level 2 on the Devices page → next heartbeat carries it with a fresh version
        // (update the SAME instance Sanctum::actingAs holds — a fresh() copy would leave it stale)
        $this->device->update(['volume_level' => 2]);
        $res = $this->postJson('/api/device/v1/heartbeat', [])->assertOk()
            ->assertJsonPath('volume', 2);
        $this->assertGreaterThan(0, $res->json('volume_ver'));

        // "Hi L-BOX ... volume up" → the assistant answers AND tells the box to act
        $reply = app(\App\Services\Lbox\AssistantService::class)->ask($this->device->fresh(), 'Please turn the volume up');
        $this->assertSame('volume_up', $reply['intent']);
        $this->assertSame('volume_up', $reply['action']);

        $reply = app(\App\Services\Lbox\AssistantService::class)->ask($this->device->fresh(), 'volume down a little');
        $this->assertSame('volume_down', $reply['action']);

        // ...while a pure question carries no action
        $reply = app(\App\Services\Lbox\AssistantService::class)->ask($this->device->fresh(), 'what is the time now');
        $this->assertNull($reply['action']);
    }

    public function test_assistant_answers_rate_questions_from_live_data_in_the_device_language(): void
    {
        $this->activateDevice();
        \App\Models\LiveRate::create([
            'country' => 'IN', 'gold' => 7250.50, 'silver' => 95.25, 'diamond' => 0,
            'effective_at' => now(),
        ]);
        // latestFor() memoizes per process — read the value the assistant will see.
        $gold = number_format((float) \App\Models\LiveRate::latestFor('IN')->gold, 2);

        Sanctum::actingAs($this->device, ['*']);

        // English device
        $res = $this->postJson('/api/device/v1/ai/ask', ['text' => 'What is the gold rate today?'])
            ->assertOk()->assertJsonPath('intent', 'gold_rate');
        $this->assertStringContainsString($gold, $res->json('answer'));

        $this->postJson('/api/device/v1/ai/ask', ['text' => 'silver price?'])
            ->assertOk()->assertJsonPath('intent', 'silver_rate');

        // Tamil device answers in Tamil
        $this->device->update(['language' => 'ta']);
        $ta = $this->postJson('/api/device/v1/ai/ask', ['text' => 'இன்று தங்கம் விலை என்ன?'])
            ->assertOk()->assertJsonPath('intent', 'gold_rate');
        $this->assertStringContainsString('தங்கம்', $ta->json('answer'));

        // unmatched → polite fallback (Tier-2 LLM hook point)
        $this->postJson('/api/device/v1/ai/ask', ['text' => 'sing me a song'])
            ->assertOk()->assertJsonPath('intent', 'fallback');

        // STT disabled in tests → voice endpoint refuses gracefully
        $this->postJson('/api/device/v1/ai/voice', [
            'audio' => \Illuminate\Http\UploadedFile::fake()->create('q.wav', 10),
        ])->assertStatus(422);
    }

    protected function activateDevice(array $extra = []): void
    {
        $this->device->update(['status' => 'active', 'pairing_code' => null, 'registered_at' => now()] + $extra);
    }

    protected function makeEmployee(string $rfidTag): Member
    {
        $stage = Rank::firstOrCreate(['code' => 'TALUK_DIRECTOR'], [
            'name' => ['en' => 'Taluk Admin'], 'depth' => 1,
            'target_bv' => 50000, 'monthly_salary' => 20000, 'tds_pct' => 0,
        ]);
        $member = Member::create([
            'member_code' => 'LBXE-'.$rfidTag, 'name' => 'Box Worker '.$rfidTag, 'phone' => sprintf('9%09d', crc32($rfidTag) % 999999999),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $stage->id,
            'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        app(EmployeeService::class)->enroll($member);
        $member->employeeProfile->update(['rfid_tag' => strtoupper($rfidTag)]);

        return $member->fresh('employeeProfile');
    }
}
