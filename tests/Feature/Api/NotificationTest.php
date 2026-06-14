<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4a — App inbox (unified notification center) for members and customers.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'NM1', 'name' => 'Notify Me', 'phone' => '9000000010',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_member_lists_notifications_newest_first(): void
    {
        $this->travelTo(now()->subMinute());
        $this->member->notify(new AppNotification('order', 'First', 'older'));
        $this->travelBack();
        $this->member->notify(new AppNotification('wallet', 'Second', 'newer', '/business/bonds', ['x' => 1]));
        Sanctum::actingAs($this->member, ['*']);

        $res = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'category', 'title', 'body', 'route', 'read', 'created_at']]]);

        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Second', $res->json('data.0.title'));
        $this->assertSame('/business/bonds', $res->json('data.0.route'));
        $this->assertFalse($res->json('data.0.read'));
    }

    public function test_unread_count_and_mark_read(): void
    {
        $this->member->notify(new AppNotification('system', 'A'));
        $this->member->notify(new AppNotification('system', 'B'));
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 2);

        $id = $this->member->notifications()->latest()->first()->id;
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('notification.read', true);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 1);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_customer_has_own_isolated_inbox(): void
    {
        $customer = Customer::create(['phone' => '9222222222', 'name' => 'Shopper']);
        $customer->notify(new AppNotification('order', 'Your order shipped'));
        $this->member->notify(new AppNotification('system', 'Member only'));

        Sanctum::actingAs($customer, ['*']);
        $res = $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Your order shipped', $res->json('data.0.title'));
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $customer = Customer::create(['phone' => '9333333333', 'name' => 'Other']);
        $customer->notify(new AppNotification('order', 'Not yours'));
        $foreignId = $customer->notifications()->first()->id;

        Sanctum::actingAs($this->member, ['*']);
        $this->postJson("/api/v1/notifications/{$foreignId}/read")->assertStatus(404);
    }
}
