<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5a — community announcement feed + app-user likes/comments.
 */
class CommunityTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;
    protected User $hq;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'CM1', 'name' => 'Community Member', 'phone' => '9000000050',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
        $this->hq = User::create(['name' => 'HQ', 'email' => 'hq@lordicl.test', 'password' => bcrypt('secret')]);
    }

    protected function makePost(string $visibility = 'public', array $extra = []): SocialPost
    {
        return SocialPost::create(array_merge([
            'author_id' => $this->hq->id, 'title' => 'Notice', 'body' => 'Hello team', 'visibility' => $visibility,
        ], $extra));
    }

    public function test_feed_requires_auth(): void
    {
        $this->getJson('/api/v1/community/feed')->assertStatus(401);
    }

    public function test_member_sees_public_and_members_posts_pinned_first(): void
    {
        $this->makePost('public', ['body' => 'pub']);
        $this->makePost('members', ['body' => 'mem']);
        $this->makePost('private', ['body' => 'secret']);
        $this->makePost('public', ['body' => 'pinned one', 'pinned' => true]);

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/community/feed')->assertOk();

        $bodies = collect($res->json('data'))->pluck('body');
        $this->assertContains('pub', $bodies);
        $this->assertContains('mem', $bodies);
        $this->assertNotContains('secret', $bodies);          // private hidden
        $this->assertSame('pinned one', $res->json('data.0.body')); // pinned floats to top
    }

    public function test_customer_sees_only_public_posts(): void
    {
        $this->makePost('public', ['body' => 'pub']);
        $this->makePost('members', ['body' => 'mem']);

        Sanctum::actingAs(Customer::create(['phone' => '9777777777', 'name' => 'Shopper']), ['*']);
        $res = $this->getJson('/api/v1/community/feed')->assertOk();

        $bodies = collect($res->json('data'))->pluck('body');
        $this->assertContains('pub', $bodies);
        $this->assertNotContains('mem', $bodies);
    }

    public function test_scheduled_future_post_is_hidden(): void
    {
        $this->makePost('public', ['body' => 'future', 'published_at' => now()->addDay()]);

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/community/feed')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_like_toggles_and_counts(): void
    {
        $post = $this->makePost('public');
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson("/api/v1/community/posts/{$post->id}/like")->assertOk()
            ->assertJsonPath('liked', true)->assertJsonPath('like_count', 1);

        // Idempotent toggle off.
        $this->postJson("/api/v1/community/posts/{$post->id}/like")->assertOk()
            ->assertJsonPath('liked', false)->assertJsonPath('like_count', 0);

        $this->getJson("/api/v1/community/posts/{$post->id}")->assertOk()->assertJsonPath('data.liked', false);
    }

    public function test_comment_add_and_list(): void
    {
        $post = $this->makePost('public');
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson("/api/v1/community/posts/{$post->id}/comments", ['body' => 'Great news!'])
            ->assertStatus(201)->assertJsonPath('data.author', 'Community Member')->assertJsonPath('data.body', 'Great news!');

        $res = $this->getJson("/api/v1/community/posts/{$post->id}/comments")->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Great news!', $res->json('data.0.body'));
    }

    public function test_customer_cannot_see_or_engage_members_post(): void
    {
        $post = $this->makePost('members');
        Sanctum::actingAs(Customer::create(['phone' => '9888888888']), ['*']);

        $this->getJson("/api/v1/community/posts/{$post->id}")->assertStatus(404);
        $this->postJson("/api/v1/community/posts/{$post->id}/like")->assertStatus(404);
        $this->postJson("/api/v1/community/posts/{$post->id}/comments", ['body' => 'x'])->assertStatus(404);
    }

    public function test_member_can_post_to_the_team_feed(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $res = $this->postJson('/api/v1/community/posts', ['title' => 'My win', 'body' => 'Closed 5 deals!'])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', 'member')
            ->assertJsonPath('data.author', 'Community Member')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.visibility', 'members');

        $this->assertDatabaseHas('social_posts', [
            'id' => $res->json('data.id'), 'poster_type' => $this->member->getMorphClass(),
            'poster_id' => $this->member->id, 'author_id' => null,
        ]);

        // Shows in the feed.
        $feed = $this->getJson('/api/v1/community/feed')->assertOk();
        $this->assertContains('Closed 5 deals!', collect($feed->json('data'))->pluck('body'));
    }

    public function test_customer_cannot_post(): void
    {
        Sanctum::actingAs(Customer::create(['phone' => '9999999900', 'name' => 'Shopper']), ['*']);
        $this->postJson('/api/v1/community/posts', ['body' => 'let me in'])->assertStatus(403);
    }

    public function test_member_deletes_own_post_but_not_others(): void
    {
        $mine = SocialPost::create(['poster_type' => $this->member->getMorphClass(), 'poster_id' => $this->member->id, 'body' => 'mine', 'visibility' => 'members']);
        $hq = $this->makePost('members', ['body' => 'hq post']);   // HQ-authored, no poster

        Sanctum::actingAs($this->member, ['*']);

        // Can't delete an HQ announcement (not theirs).
        $this->deleteJson("/api/v1/community/posts/{$hq->id}")->assertStatus(404);
        // Can delete their own.
        $this->deleteJson("/api/v1/community/posts/{$mine->id}")->assertOk()->assertJsonPath('ok', true);
        $this->assertSoftDeleted('social_posts', ['id' => $mine->id]);
    }
}
