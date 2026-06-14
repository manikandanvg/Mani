<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\Rank;
use App\Notifications\AppNotification;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4b — push-token registration + the FCM notification channel fan-out.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'PT1', 'name' => 'Pusher', 'phone' => '9000000020',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    public function test_register_requires_authentication(): void
    {
        $this->postJson('/api/v1/device-tokens', ['token' => 'abc'])->assertStatus(401);
    }

    public function test_member_registers_a_device_token(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/device-tokens', ['token' => 'tok-1', 'platform' => 'android'])
            ->assertStatus(201)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'tok-1', 'owner_type' => $this->member->getMorphClass(),
            'owner_id' => $this->member->id, 'platform' => 'android',
        ]);
    }

    public function test_reregistering_same_token_reowns_it(): void
    {
        $customer = Customer::create(['phone' => '9444444444', 'name' => 'C']);
        DeviceToken::create(['token' => 'shared', 'owner_type' => $customer->getMorphClass(), 'owner_id' => $customer->id, 'platform' => 'android', 'provider' => 'fcm']);

        Sanctum::actingAs($this->member, ['*']);
        $this->postJson('/api/v1/device-tokens', ['token' => 'shared'])->assertStatus(201);

        $this->assertSame(1, DeviceToken::where('token', 'shared')->count());
        $this->assertDatabaseHas('device_tokens', ['token' => 'shared', 'owner_id' => $this->member->id, 'owner_type' => $this->member->getMorphClass()]);
    }

    public function test_unregister_only_removes_own_token(): void
    {
        Sanctum::actingAs($this->member, ['*']);
        DeviceToken::create(['token' => 'mine', 'owner_type' => $this->member->getMorphClass(), 'owner_id' => $this->member->id]);
        $other = Customer::create(['phone' => '9555555555']);
        DeviceToken::create(['token' => 'theirs', 'owner_type' => $other->getMorphClass(), 'owner_id' => $other->id]);

        $this->deleteJson('/api/v1/device-tokens', ['token' => 'theirs'])->assertOk();
        $this->assertDatabaseHas('device_tokens', ['token' => 'theirs']);   // untouched

        $this->deleteJson('/api/v1/device-tokens', ['token' => 'mine'])->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => 'mine']);
    }

    public function test_notification_pushes_to_devices_and_prunes_stale_tokens(): void
    {
        $this->member->deviceTokens()->create(['token' => 'good', 'platform' => 'android', 'provider' => 'fcm']);
        $this->member->deviceTokens()->create(['token' => 'dead', 'platform' => 'android', 'provider' => 'fcm']);

        $fake = new class extends PushSender {
            public array $received = [];

            public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
            {
                $this->received = $tokens;

                return ['ok' => false, 'sent' => 1, 'failed' => 1, 'stale' => ['dead']];
            }
        };
        $this->app->instance(PushSender::class, $fake);

        $this->member->notify(new AppNotification('order', 'Hi', 'body', '/orders/1'));

        // FCM channel saw both tokens...
        $this->assertEqualsCanonicalizing(['good', 'dead'], $fake->received);
        // ...the dead one was pruned, the good one kept...
        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'good']);
        // ...and the inbox (database channel) still recorded it.
        $this->assertSame(1, $this->member->notifications()->count());
    }

    public function test_push_is_a_noop_when_fcm_unconfigured(): void
    {
        // Default test env has no FCM creds — notifying must not throw, inbox still works.
        $this->member->deviceTokens()->create(['token' => 'x', 'platform' => 'android', 'provider' => 'fcm']);
        $this->member->notify(new AppNotification('system', 'No push configured'));

        $this->assertSame(1, $this->member->notifications()->count());
        $this->assertDatabaseHas('device_tokens', ['token' => 'x']);   // not pruned
    }
}
