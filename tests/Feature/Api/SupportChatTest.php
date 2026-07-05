<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SupportThread;
use App\Services\Support\SupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4c — app ⇄ HQ support chat.
 */
class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'SC1', 'name' => 'Chatter', 'phone' => '9000000030',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/support/threads')->assertStatus(401);
    }

    public function test_user_opens_thread_and_lists_it(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/support/threads', ['subject' => 'Help', 'body' => 'My order is late'])
            ->assertStatus(201)
            ->assertJsonPath('data.subject', 'Help')
            ->assertJsonPath('data.status', 'open');

        $list = $this->getJson('/api/v1/support/threads')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Help', $list->json('data.0.subject'));
    }

    public function test_show_returns_transcript_and_reply_appends(): void
    {
        Sanctum::actingAs($this->member, ['*']);
        $thread = SupportThread::create(['owner_type' => $this->member->getMorphClass(), 'owner_id' => $this->member->id, 'subject' => 'Q']);
        $thread->messages()->create(['sender' => 'user', 'body' => 'first']);

        $this->postJson("/api/v1/support/threads/{$thread->id}/messages", ['body' => 'second'])
            ->assertStatus(201)->assertJsonPath('data.sender', 'user');

        $show = $this->getJson("/api/v1/support/threads/{$thread->id}")->assertOk();
        $this->assertCount(2, $show->json('messages'));
        $this->assertSame('second', $show->json('messages.1.body'));
    }

    public function test_cannot_access_another_users_thread(): void
    {
        $other = Customer::create(['phone' => '9666666666', 'name' => 'Other']);
        $thread = SupportThread::create(['owner_type' => $other->getMorphClass(), 'owner_id' => $other->id, 'subject' => 'theirs']);

        Sanctum::actingAs($this->member, ['*']);
        $this->getJson("/api/v1/support/threads/{$thread->id}")->assertStatus(404);
        $this->postJson("/api/v1/support/threads/{$thread->id}/messages", ['body' => 'sneak'])->assertStatus(404);
    }

    public function test_hq_reply_notifies_owner_and_counts_as_unread(): void
    {
        $thread = app(SupportService::class)->userMessage(null, $this->member, 'Need help')->thread;

        // HQ answers a moment later (replies arrive after the user's last message).
        $this->travel(1)->minutes();
        app(SupportService::class)->supportReply($thread, 'We are on it', supportUserId: null);
        $this->travel(1)->minutes();   // and the user reads it later still

        // Owner got an inbox notification...
        $this->assertSame(1, $this->member->notifications()->count());
        $this->assertSame('support', $this->member->notifications()->first()->data['category']);

        // ...and the reply is unread until they open the thread.
        Sanctum::actingAs($this->member, ['*']);
        $this->getJson('/api/v1/support/unread-count')->assertOk()->assertJsonPath('count', 1);

        $this->getJson("/api/v1/support/threads/{$thread->id}")->assertOk();   // opening marks read
        $this->getJson('/api/v1/support/unread-count')->assertOk()->assertJsonPath('count', 0);
    }
}
