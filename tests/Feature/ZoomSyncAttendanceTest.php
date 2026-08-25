<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * zoom:sync-attendance (2026-08-25): Zoom's participant report backs up the
 * participant webhooks so "verified minutes" never stay at zero because an
 * event subscription was mis-configured or a delivery was missed.
 */
class ZoomSyncAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.zoom.account_id' => 'acc', 'services.zoom.client_id' => 'cid', 'services.zoom.client_secret' => 'sec',
        ]);
    }

    protected function endedMeeting(): Meeting
    {
        return Meeting::create([
            'title' => 'meet_25', 'platform' => 'zoom', 'join_url' => 'https://zoom.us/j/89748872092',
            'meeting_id' => '89748872092', 'scheduled_at' => now()->subHours(3),
            'duration_min' => 60, 'visibility' => 'public', 'is_published' => true,
        ]);
    }

    protected function member(string $code, string $name): Member
    {
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);

        return Member::create([
            'member_code' => $code, 'name' => $name, 'phone' => '90000000' . substr($code, -2),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    protected function fakeZoom(array $participants, ?string $secondPage = null): void
    {
        $first = ['participants' => $participants, 'next_page_token' => $secondPage ? 'p2' : ''];
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/89748872092/participants*' => Http::sequence()
                ->push($first)
                ->push(['participants' => $secondPage ? [['user_id' => '9', 'name' => $secondPage, 'join_time' => now()->subHours(3)->toIso8601String(), 'duration' => 120]] : [], 'next_page_token' => '']),
        ]);
    }

    public function test_report_creates_verified_rows_matched_to_members(): void
    {
        $meeting = $this->endedMeeting();
        $priya = $this->member('LJW77', 'Priya');
        $this->fakeZoom([
            [
                'id' => 'uuid-1', 'user_id' => '16778240', 'name' => 'Priya · LJW77', 'user_email' => '',
                'join_time' => now()->subHours(3)->toIso8601String(),
                'leave_time' => now()->subHours(3)->addMinutes(25)->toIso8601String(),
                'duration' => 1500,
            ],
            [
                'id' => 'uuid-2', 'user_id' => '16778241', 'name' => 'Guest', 'user_email' => '',
                'join_time' => now()->subHours(3)->addMinutes(2)->toIso8601String(),
                'duration' => 600,   // no leave_time → derived from duration
            ],
        ]);

        $this->artisan('zoom:sync-attendance')->assertSuccessful();

        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id, 'member_id' => $priya->id, 'source' => 'zoom',
            'zoom_participant_id' => '16778240', 'duration_min' => 25,
        ]);
        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id, 'member_id' => null, 'participant_name' => 'Guest', 'duration_min' => 10,
        ]);
        $this->assertSame(2, MeetingAttendance::where('source', 'zoom')->count());
    }

    public function test_rerun_completes_a_webhook_row_instead_of_duplicating_it(): void
    {
        $meeting = $this->endedMeeting();
        $priya = $this->member('LJW77', 'Priya');
        $joined = now()->subHours(3);
        // the webhook logged the join but the leave event never arrived
        MeetingAttendance::create([
            'meeting_id' => $meeting->id, 'member_id' => null, 'participant_name' => 'Priya · LJW77',
            'zoom_participant_id' => '16778240', 'source' => 'zoom', 'joined_at' => $joined,
        ]);
        $this->fakeZoom([[
            'id' => 'uuid-1', 'user_id' => '16778240', 'name' => 'Priya · LJW77',
            'join_time' => $joined->toIso8601String(), 'leave_time' => $joined->copy()->addMinutes(40)->toIso8601String(), 'duration' => 2400,
        ]]);

        $this->artisan('zoom:sync-attendance')->assertSuccessful();
        $this->artisan('zoom:sync-attendance')->assertSuccessful();

        $this->assertSame(1, MeetingAttendance::where('source', 'zoom')->count());
        $this->assertDatabaseHas('meeting_attendances', [
            'meeting_id' => $meeting->id, 'member_id' => $priya->id, 'zoom_participant_id' => '16778240', 'duration_min' => 40,
        ]);
    }

    public function test_missing_scope_is_reported_not_fatal(): void
    {
        $this->endedMeeting();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok']),
            'api.zoom.us/v2/past_meetings/*' => Http::response(['code' => 4711, 'message' => 'does not contain scopes'], 400),
        ]);

        $this->artisan('zoom:sync-attendance')->assertFailed();
        $this->assertSame(0, MeetingAttendance::count());
    }

    public function test_upcoming_and_live_meetings_are_skipped_unless_named(): void
    {
        $live = Meeting::create([
            'title' => 'live now', 'platform' => 'zoom', 'join_url' => 'https://zoom.us/j/1', 'meeting_id' => '89748872092',
            'scheduled_at' => now()->subMinutes(10), 'duration_min' => 60, 'visibility' => 'public', 'is_published' => true,
        ]);
        $this->fakeZoom([[
            'user_id' => '1', 'name' => 'Early Bird', 'join_time' => now()->subMinutes(9)->toIso8601String(), 'duration' => 300,
        ]]);

        $this->artisan('zoom:sync-attendance')->assertSuccessful();
        $this->assertSame(0, MeetingAttendance::count());

        $this->artisan('zoom:sync-attendance', ['--meeting' => $live->id])->assertSuccessful();
        $this->assertSame(1, MeetingAttendance::count());
    }
}
