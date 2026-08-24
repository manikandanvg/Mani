<?php

namespace Tests\Feature\Api;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Native in-app Zoom join (2026-08-24): the app joins with Zoom's native
 * Meeting SDK, so the API flags SDK-joinable meetings and hands out a
 * server-signed SDK JWT with the join parameters.
 */
class NativeZoomJoinTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = Member::create([
            'member_code' => 'NZ1', 'name' => 'Native Joiner', 'phone' => '9000000710', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    protected function configureSdk(): void
    {
        config(['services.zoom.sdk_client_id' => 'zoom_client_x', 'services.zoom.sdk_client_secret' => 'zoom_secret_x']);
    }

    protected function makeMeeting(array $overrides = []): Meeting
    {
        return Meeting::create($overrides + [
            'title' => 'Monthly Growth Call', 'platform' => 'zoom',
            'join_url' => 'https://zoom.us/j/9876543210', 'meeting_id' => '987 654 3210', 'passcode' => 'gold',
            'scheduled_at' => now()->addHour(), 'duration_min' => 60,
            'visibility' => 'members', 'is_published' => true,
        ]);
    }

    public function test_sdk_token_carries_a_signed_jwt_and_join_params_and_logs_attendance(): void
    {
        $this->configureSdk();
        $meeting = $this->makeMeeting();
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('upcoming.0.sdk_join', true);

        $res = $this->getJson('/api/v1/meetings/' . $meeting->id . '/sdk-token')->assertOk();
        $this->assertSame('zoom_client_x', $res->json('client_id'));
        $this->assertSame('9876543210', $res->json('meeting_number'));
        $this->assertSame('gold', $res->json('passcode'));
        $this->assertSame('Native Joiner · NZ1', $res->json('display_name'));
        $this->assertSame('zoom.us', $res->json('domain'));

        [$h, $p, $s] = explode('.', $res->json('jwt'));
        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame('zoom_client_x', $payload['appKey']);
        $this->assertSame('9876543210', $payload['mn']);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", 'zoom_secret_x', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s);

        // Opening the meeting in-app counts as attendance, exactly once.
        $this->getJson('/api/v1/meetings/' . $meeting->id . '/sdk-token')->assertOk();
        $this->assertSame(1, MeetingAttendance::where('meeting_id', $meeting->id)->where('member_id', $this->member->id)->count());
        $this->assertSame('app', MeetingAttendance::first()->source);
    }

    public function test_sdk_join_is_false_and_token_unavailable_when_sdk_not_configured(): void
    {
        config(['services.zoom.sdk_client_id' => null, 'services.zoom.sdk_client_secret' => null]);
        $meeting = $this->makeMeeting();
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('upcoming.0.sdk_join', false);
        $this->getJson('/api/v1/meetings/' . $meeting->id . '/sdk-token')->assertStatus(503);
    }

    public function test_meetings_without_a_numeric_zoom_id_or_unpublished_cannot_be_joined_in_app(): void
    {
        $this->configureSdk();
        Sanctum::actingAs($this->member, ['*']);

        $noId = $this->makeMeeting(['meeting_id' => null, 'platform' => 'meet', 'join_url' => 'https://meet.google.com/abc']);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonPath('upcoming.0.sdk_join', false);
        $this->getJson('/api/v1/meetings/' . $noId->id . '/sdk-token')->assertStatus(404);

        $draft = $this->makeMeeting(['is_published' => false]);
        $this->getJson('/api/v1/meetings/' . $draft->id . '/sdk-token')->assertStatus(404);
    }
}
