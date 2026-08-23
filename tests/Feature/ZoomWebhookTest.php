<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zoom attendance webhooks (board phase-1, 2026-08-21): endpoint validation
 * challenge, HMAC signature enforcement, and participant joined/left rows.
 */
class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zoom.webhook_secret' => self::SECRET]);
    }

    protected function signedHeaders(array $payload): array
    {
        $ts = (string) time();

        return [
            'x-zm-request-timestamp' => $ts,
            'x-zm-signature' => 'v0=' . hash_hmac('sha256', "v0:{$ts}:" . json_encode($payload), self::SECRET),
        ];
    }

    protected function meeting(): Meeting
    {
        return Meeting::create([
            'title' => 'Weekly Training', 'platform' => 'zoom', 'join_url' => 'https://zoom.us/j/123456789',
            'meeting_id' => '123456789', 'scheduled_at' => now()->addHour(),
            'duration_min' => 60, 'visibility' => 'members', 'is_published' => true,
        ]);
    }

    public function test_url_validation_challenge_is_answered(): void
    {
        $res = $this->postJson('/webhooks/zoom', [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'abc123'],
        ]);

        $res->assertOk()->assertJson([
            'plainToken' => 'abc123',
            'encryptedToken' => hash_hmac('sha256', 'abc123', self::SECRET),
        ]);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->meeting();
        $payload = ['event' => 'meeting.participant_joined', 'payload' => ['object' => ['id' => '123456789']]];

        $this->postJson('/webhooks/zoom', $payload, ['x-zm-signature' => 'v0=wrong', 'x-zm-request-timestamp' => '1'])
            ->assertStatus(401);
    }

    public function test_participant_joined_and_left_record_attendance(): void
    {
        $meeting = $this->meeting();

        $joined = [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => [
                'id' => '123456789',
                'participant' => ['user_id' => '16778240', 'user_name' => 'Ravi Kumar', 'join_time' => now()->toIso8601String()],
            ]],
        ];
        $this->postJson('/webhooks/zoom', $joined, $this->signedHeaders($joined))->assertOk();

        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id, 'source' => 'zoom',
            'participant_name' => 'Ravi Kumar', 'zoom_participant_id' => '16778240',
        ]);

        $left = [
            'event' => 'meeting.participant_left',
            'payload' => ['object' => [
                'id' => '123456789',
                'participant' => ['user_id' => '16778240', 'user_name' => 'Ravi Kumar', 'leave_time' => now()->addMinutes(42)->toIso8601String()],
            ]],
        ];
        $this->postJson('/webhooks/zoom', $left, $this->signedHeaders($left))->assertOk();

        $row = \App\Models\MeetingAttendance::first();
        $this->assertNotNull($row->left_at);
        $this->assertSame(42, $row->duration_min);
    }

    public function test_unknown_meeting_is_acknowledged_and_ignored(): void
    {
        $payload = [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => ['id' => '999', 'participant' => ['user_name' => 'X']]],
        ];
        $this->postJson('/webhooks/zoom', $payload, $this->signedHeaders($payload))->assertOk();
        $this->assertDatabaseCount('meeting_attendances', 0);
    }
    public function test_member_code_in_display_name_ties_the_row_to_the_member(): void
    {
        $meeting = $this->meeting();
        $rank = \App\Models\Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $member = \App\Models\Member::create([
            'member_code' => 'LJW77', 'name' => 'Priya', 'phone' => '9000000077',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
        // a second "Priya" proves the code wins over the name match
        \App\Models\Member::create([
            'member_code' => 'LJW78', 'name' => 'Priya', 'phone' => '9000000078',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);

        $joined = [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => [
                'id' => '123456789',
                'participant' => ['user_id' => '77', 'user_name' => 'Priya · ljw77', 'join_time' => now()->toIso8601String()],
            ]],
        ];
        $this->postJson('/webhooks/zoom', $joined, $this->signedHeaders($joined))->assertOk();

        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id, 'member_id' => $member->id, 'zoom_participant_id' => '77',
        ]);
    }

    public function test_meeting_ended_closes_rows_that_never_got_a_leave_event(): void
    {
        $meeting = $this->meeting();
        $join = now()->subMinutes(30);
        $open = \App\Models\MeetingAttendance::create([
            'meeting_id' => $meeting->id, 'participant_name' => 'Ravi', 'zoom_participant_id' => '1',
            'source' => 'zoom', 'joined_at' => $join,
        ]);
        $closed = \App\Models\MeetingAttendance::create([
            'meeting_id' => $meeting->id, 'participant_name' => 'Sita', 'zoom_participant_id' => '2',
            'source' => 'zoom', 'joined_at' => $join, 'left_at' => $join->copy()->addMinutes(5), 'duration_min' => 5,
        ]);

        $ended = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '123456789', 'end_time' => $join->copy()->addMinutes(30)->toIso8601String()]],
        ];
        $this->postJson('/webhooks/zoom', $ended, $this->signedHeaders($ended))->assertOk();

        $this->assertSame(30, $open->fresh()->duration_min);
        $this->assertNotNull($open->fresh()->left_at);
        $this->assertSame(5, $closed->fresh()->duration_min);   // untouched
    }

    public function test_unique_attendee_count_merges_app_and_zoom_rows_of_one_member(): void
    {
        $meeting = $this->meeting();
        $rank = \App\Models\Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $member = \App\Models\Member::create([
            'member_code' => 'LJW79', 'name' => 'Kumar', 'phone' => '9000000079',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
        \App\Models\MeetingAttendance::create(['meeting_id' => $meeting->id, 'member_id' => $member->id, 'source' => 'app', 'joined_at' => now()]);
        \App\Models\MeetingAttendance::create(['meeting_id' => $meeting->id, 'member_id' => $member->id, 'source' => 'zoom', 'zoom_participant_id' => '9', 'joined_at' => now()]);
        \App\Models\MeetingAttendance::create(['meeting_id' => $meeting->id, 'participant_name' => 'Guest', 'zoom_participant_id' => '10', 'source' => 'zoom', 'joined_at' => now()]);
        \App\Models\MeetingAttendance::create(['meeting_id' => $meeting->id, 'participant_name' => 'Guest', 'zoom_participant_id' => '10', 'source' => 'zoom', 'joined_at' => now()]);   // rejoin

        $this->assertSame(4, $meeting->attendances()->count());
        $this->assertSame(2, $meeting->uniqueAttendeeCount());
    }
}
