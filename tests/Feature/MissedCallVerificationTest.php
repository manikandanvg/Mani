<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\PhoneVerification;
use App\Models\Rank;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Board 2026-11-02: no OTP. A distributor proves a number by a missed call from it to the Exotel
 * virtual number; Exotel's Passthru webhook reports the caller and the pending attempt is verified.
 */
class MissedCallVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Services\Activity\ActivityLogger::$enabled = true;
        config(['services.exotel' => ['sid' => 'lordicl1', 'api_key' => 'k', 'api_token' => 't', 'subdomain' => 'api.exotel.com',
            'virtual_number' => '04446312345', 'webhook_key' => 'hook-secret', 'verification_minutes' => 10]]);
        Member::create(['member_code' => 'LJW900', 'name' => 'Murugan', 'phone' => '9876543210', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id')]);
    }

    public function test_a_registered_number_is_verified_by_its_missed_call_and_the_app_can_poll_it(): void
    {
        $start = $this->postJson('/api/v1/phone/missed-call/start', ['phone' => '+91 98765 43210', 'device_uid' => 'dev-1'])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('dial_number', '04446312345')->json();
        $id = $start['id'];
        $this->assertSame('9876543210', PhoneVerification::find($id)->phone);

        // the same number asked again reuses the open attempt
        $this->postJson('/api/v1/phone/missed-call/start', ['phone' => '9876543210'])->assertOk()->assertJsonPath('id', $id);

        // polling needs the number too (ids are guessable)
        $this->getJson("/api/v1/phone/missed-call/{$id}")->assertNotFound();
        $this->getJson("/api/v1/phone/missed-call/{$id}?phone=9876543210")->assertOk()->assertJsonPath('status', 'pending');

        // Exotel reports the call (GET, as Passthru does); wrong key is refused
        $this->get('/webhooks/exotel/missed-call?key=wrong&CallFrom=09876543210&CallSid=abc')->assertForbidden();
        $this->get('/webhooks/exotel/missed-call?key=hook-secret&CallFrom=09876543210&CallTo=04446312345&CallSid=abc&Direction=incoming')
            ->assertOk()->assertSee('verified');

        $row = PhoneVerification::find($id);
        $this->assertSame('verified', $row->status);
        $this->assertSame('9876543210', $row->caller);
        $this->assertSame('abc', $row->call_sid);
        $this->assertSame('dev-1', $row->device_uid);
        $this->getJson("/api/v1/phone/missed-call/{$id}?phone=9876543210")->assertOk()->assertJsonPath('status', 'verified');
        $this->assertSame(1, ActivityLog::where('action', 'missed_call_verified')->count());

        // Exotel retrying the same call changes nothing; a call from a number nobody asked about is logged, not matched
        $this->get('/webhooks/exotel/missed-call?key=hook-secret&CallFrom=09876543210&CallSid=abc')->assertOk();
        $this->get('/webhooks/exotel/missed-call?key=hook-secret&CallFrom=09000000000&CallSid=zzz')->assertOk()->assertSee('ignored');
        $this->assertSame(1, PhoneVerification::count());
        $this->assertSame(1, ActivityLog::where('action', 'missed_call_unmatched')->count());
    }

    public function test_unregistered_numbers_expiry_and_missing_setup_are_refused(): void
    {
        $this->postJson('/api/v1/phone/missed-call/start', ['phone' => '9111111111'])->assertStatus(422);

        $row = app(\App\Services\Phone\MissedCallVerification::class)->start('9876543210');
        $row->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->get('/webhooks/exotel/missed-call?key=hook-secret&CallFrom=9876543210&CallSid=late')->assertOk()->assertSee('ignored');
        $this->getJson("/api/v1/phone/missed-call/{$row->id}?phone=9876543210")->assertOk()->assertJsonPath('status', 'expired');

        config(['services.exotel.virtual_number' => null]);
        $this->postJson('/api/v1/phone/missed-call/start', ['phone' => '9876543210'])->assertStatus(503);
    }
}
