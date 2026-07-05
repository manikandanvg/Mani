<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6a — Live & Learn meetings list (visibility-scoped, status-grouped).
 */
class MeetingTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'MT1', 'name' => 'Learner', 'phone' => '9000000060',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    protected function meeting(array $attrs = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'Training', 'join_url' => 'https://zoom.us/j/123', 'platform' => 'zoom',
            'scheduled_at' => now()->addHour(), 'duration_min' => 60, 'visibility' => 'members', 'is_published' => true,
        ], $attrs));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/meetings')->assertStatus(401);
    }

    public function test_groups_by_status_for_member(): void
    {
        $this->meeting(['title' => 'Soon', 'scheduled_at' => now()->addHour()]);                 // upcoming
        $this->meeting(['title' => 'Now', 'scheduled_at' => now()->subMinutes(10), 'duration_min' => 60]); // live
        $this->meeting(['title' => 'Done', 'scheduled_at' => now()->subDays(1), 'duration_min' => 30]);    // ended

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/meetings')->assertOk()
            ->assertJsonStructure(['live', 'upcoming', 'past']);

        $this->assertSame('Now', $res->json('live.0.title'));
        $this->assertSame('live', $res->json('live.0.status'));
        $this->assertSame('Soon', $res->json('upcoming.0.title'));
        $this->assertSame('Done', $res->json('past.0.title'));
        $this->assertSame('https://zoom.us/j/123', $res->json('upcoming.0.join_url'));
    }

    public function test_visibility_and_publish_scoping(): void
    {
        $this->meeting(['title' => 'Pub', 'visibility' => 'public']);
        $this->meeting(['title' => 'Mem', 'visibility' => 'members']);
        $this->meeting(['title' => 'Draft', 'visibility' => 'public', 'is_published' => false]);

        // Customer sees only published public meetings.
        Sanctum::actingAs(Customer::create(['phone' => '9333000111']), ['*']);
        $res = $this->getJson('/api/v1/meetings')->assertOk();
        $titles = collect($res->json('upcoming'))->pluck('title');
        $this->assertContains('Pub', $titles);
        $this->assertNotContains('Mem', $titles);
        $this->assertNotContains('Draft', $titles);

        // Member also sees members-only.
        Sanctum::actingAs($this->member, ['*']);
        $titles2 = collect($this->getJson('/api/v1/meetings')->json('upcoming'))->pluck('title');
        $this->assertContains('Mem', $titles2);
        $this->assertContains('Pub', $titles2);
    }

    public function test_old_meetings_beyond_7_days_excluded(): void
    {
        $this->meeting(['title' => 'Ancient', 'scheduled_at' => now()->subDays(30)]);

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/meetings')->assertOk();
        $this->assertCount(0, $res->json('past'));
    }
}
