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
}
