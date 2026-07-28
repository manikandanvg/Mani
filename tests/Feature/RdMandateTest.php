<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\RdMandate;
use App\Services\Payment\RazorpaySubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RdMandateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.razorpay.key' => 'rzp_test_key',
            'services.razorpay.secret' => 'secret',
            'services.razorpay.webhook_secret' => 'whsec',
        ]);
    }

    protected function rdBond(): Bond
    {
        $branch = Branch::create(['name' => 'RD Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = Plan::create(['code' => 'GS', 'name' => ['en' => 'Gold Saving'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);
        $member = Member::create([
            'member_code' => 'RDM1', 'name' => 'Saver', 'phone' => '9677676034', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active', 'branch_id' => $branch->id,
        ]);

        return Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
            'bond_date' => now(), 'value' => 5000, 'lvlcom_count' => 11, 'status' => 'active',
        ]);
    }

    public function test_enrol_creates_a_mandate_with_authorisation_link(): void
    {
        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_X', 'status' => 'created'], 200),
            'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_X', 'status' => 'created', 'short_url' => 'https://rzp.io/i/abc'], 200),
        ]);

        $bond = $this->rdBond();
        $mandate = app(RazorpaySubscriptionService::class)->enrol($bond);

        $this->assertNotNull($mandate);
        $this->assertEquals('sub_X', $mandate->razorpay_subscription_id);
        $this->assertEquals(5000, (float) $mandate->amount);
        $this->assertEquals(11, $mandate->total_count);            // remaining installments
        $this->assertEquals('https://rzp.io/i/abc', $mandate->short_url);
    }

    public function test_webhook_charge_records_installment_without_cash_or_margin(): void
    {
        $bond = $this->rdBond();
        $mandate = RdMandate::create([
            'bond_id' => $bond->id, 'member_id' => $bond->member_id, 'branch_id' => $bond->branch_id,
            'razorpay_subscription_id' => 'sub_X', 'amount' => 5000, 'total_count' => 11, 'status' => 'active',
        ]);

        $this->postWebhook('subscription.charged', [
            'subscription' => ['entity' => ['id' => 'sub_X', 'status' => 'active', 'paid_count' => 1, 'charge_at' => 1735689600]],
            'payment' => ['entity' => ['id' => 'pay_1', 'amount' => 500000]],
        ])->assertOk();

        // installment recorded against the RD's own branch — no bill-margin, no cash movement
        $this->assertDatabaseHas('rd_entries', [
            'bond_id' => $bond->id, 'value' => 5000, 'due_count' => 1, 'branch_id' => $bond->branch_id,
        ]);
        $this->assertEquals(1, $mandate->fresh()->paid_count);
        $this->assertEquals(0, \App\Models\ResellerCommission::count());
        $this->assertEquals(0, \App\Models\StockMovement::count());

        // same webhook redelivered → no duplicate installment (idempotent on payment id)
        $this->postWebhook('subscription.charged', [
            'subscription' => ['entity' => ['id' => 'sub_X', 'paid_count' => 1]],
            'payment' => ['entity' => ['id' => 'pay_1', 'amount' => 500000]],
        ])->assertOk();
        $this->assertEquals(1, RdEntry::where('bond_id', $bond->id)->count());
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $bond = $this->rdBond();
        RdMandate::create(['bond_id' => $bond->id, 'member_id' => $bond->member_id, 'razorpay_subscription_id' => 'sub_X', 'amount' => 5000, 'status' => 'active']);

        $body = json_encode(['event' => 'subscription.charged', 'payload' => ['subscription' => ['entity' => ['id' => 'sub_X']], 'payment' => ['entity' => ['id' => 'pay_9']]]]);
        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => 'deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertEquals(0, RdEntry::where('bond_id', $bond->id)->count());
    }

    /** POST a signed Razorpay webhook (HMAC-SHA256 of the raw body). */
    protected function postWebhook(string $event, array $payload)
    {
        $body = json_encode(['event' => $event, 'payload' => $payload]);
        $sig = hash_hmac('sha256', $body, 'whsec');

        return $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
