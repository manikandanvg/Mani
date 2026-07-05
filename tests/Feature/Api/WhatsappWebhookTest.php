<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SupportThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Phase 4c-2 — inbound WhatsApp webhook routes messages into support threads.
 */
class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'WW1', 'name' => 'WhatsApper', 'phone' => '9000000040',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    public function test_known_member_message_opens_a_support_thread(): void
    {
        // Gateway sends the international form; we match on the local number.
        $this->postJson('/api/v1/webhooks/whatsapp', ['from' => '919000000040', 'message' => 'Hi, need help'])
            ->assertOk()->assertJsonPath('handled', true);

        $thread = SupportThread::where('owner_type', $this->member->getMorphClass())
            ->where('owner_id', $this->member->id)->first();
        $this->assertNotNull($thread);
        $msg = $thread->messages()->first();
        $this->assertSame('user', $msg->sender);
        $this->assertSame('whatsapp', $msg->source);
        $this->assertSame('Hi, need help', $msg->body);
    }

    public function test_reuses_existing_open_thread(): void
    {
        $thread = SupportThread::create(['owner_type' => $this->member->getMorphClass(), 'owner_id' => $this->member->id, 'subject' => 'Existing', 'status' => 'open']);

        $this->postJson('/api/v1/webhooks/whatsapp', ['from' => '9000000040', 'message' => 'second channel'])
            ->assertOk()->assertJsonPath('thread_id', $thread->id);

        $this->assertSame(1, SupportThread::count());
        $this->assertSame('second channel', $thread->messages()->latest('id')->first()->body);
    }

    public function test_nested_payload_shape_is_parsed(): void
    {
        $customer = Customer::create(['phone' => '9111111199', 'name' => 'Shopper']);

        $this->postJson('/api/v1/webhooks/whatsapp', ['data' => ['number' => '9111111199', 'text' => 'from nested']])
            ->assertOk()->assertJsonPath('handled', true);

        $thread = SupportThread::where('owner_type', $customer->getMorphClass())->where('owner_id', $customer->id)->first();
        $this->assertSame('from nested', $thread->messages()->first()->body);
    }

    public function test_unknown_sender_is_accepted_but_not_handled(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', ['from' => '918888888888', 'message' => 'who am i'])
            ->assertOk()->assertJsonPath('handled', false);

        $this->assertSame(0, SupportThread::count());
    }

    public function test_shared_secret_is_enforced_when_configured(): void
    {
        Config::set('services.whatsapp.webhook_token', 's3cret');

        // Missing/!wrong token → 401.
        $this->postJson('/api/v1/webhooks/whatsapp', ['from' => '9000000040', 'message' => 'x'])->assertStatus(401);

        // Correct token (header) → handled.
        $this->withHeaders(['X-Webhook-Token' => 's3cret'])
            ->postJson('/api/v1/webhooks/whatsapp', ['from' => '9000000040', 'message' => 'x'])
            ->assertOk()->assertJsonPath('handled', true);
    }

    public function test_verify_challenge_is_echoed(): void
    {
        Config::set('services.whatsapp.verify_token', 'vtok');

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=vtok&hub_challenge=12345')
            ->assertOk()->assertSee('12345');
    }
}
