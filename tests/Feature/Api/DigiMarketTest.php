<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\DigiGoldPurchase;
use App\Models\DigiGoldTxn;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\Setting;
use App\Services\Wallet\DigiMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Digi Market (board 2026-08-11): gold + silver investment purses. Buy from the
 * cash wallet (instant) or online via Razorpay (idempotent across browser verify
 * and webhook); withdraw metal → cash wallet at the live rate minus the
 * admin-configured platform fee. Scan & Pay from metal no longer exists.
 */
class DigiMarketTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        LiveRate::create(['country' => 'IN', 'gold' => 10000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->member = Member::create([
            'member_code' => 'DG1', 'name' => 'Metal Buyer', 'phone' => '9000000200', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $this->member->id]);
    }

    protected function configureGateway(): void
    {
        config([
            'services.razorpay.key' => 'rzp_test_key',
            'services.razorpay.secret' => 'rzp_test_secret',
            'services.razorpay.webhook_secret' => 'whsec_test',
        ]);
    }

    public function test_summary_shows_both_metals_and_quotes_work_by_amount_and_weight(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/digimarket')
            ->assertOk()
            ->assertJsonPath('metals.gold.rate', 10000)
            ->assertJsonPath('metals.silver.rate', 100)
            ->assertJsonPath('metals.gold.grams', 0)
            ->assertJsonPath('platform_fee_pct', (int) DigiMarketService::DEFAULT_FEE_PCT);

        $this->postJson('/api/v1/digimarket/quote', ['metal' => 'gold', 'amount' => 1000])
            ->assertOk()
            ->assertJsonPath('grams', 0.1);

        // by weight: 5 g of silver at ₹100/g = ₹500
        $this->postJson('/api/v1/digimarket/quote', ['metal' => 'silver', 'grams' => 5])
            ->assertOk()
            ->assertJsonPath('amount', 500);

        // withdraw direction includes the platform fee (default 1%)
        $this->postJson('/api/v1/digimarket/quote', ['metal' => 'gold', 'amount' => 1000, 'direction' => 'withdraw'])
            ->assertOk()
            ->assertJsonPath('fee', 10)
            ->assertJsonPath('net', 990);
    }

    public function test_digimarket_is_member_only(): void
    {
        $customer = Customer::create(['phone' => '9000000300', 'name' => 'Shopper']);
        Sanctum::actingAs($customer, ['*']);

        $this->getJson('/api/v1/digimarket')->assertStatus(403);
        $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 1000, 'funding' => 'wallet'])->assertStatus(403);
    }

    public function test_wallet_funded_buy_settles_instantly_and_online_needs_gateway(): void
    {
        // dev .env may carry gateway keys — the online branch needs them absent
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);
        MemberWallet::find($this->member->id)->update(['cash_balance' => 3000]);
        Sanctum::actingAs($this->member, ['*']);

        // online buy refused without a gateway…
        $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 1000, 'funding' => 'online'])
            ->assertStatus(422);

        // …but the wallet path works without one: cash out, grams in, instantly paid.
        $res = $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 2000, 'funding' => 'wallet'])
            ->assertStatus(201);
        $this->assertSame('paid', $res->json('purchase.status'));
        $this->assertSame('wallet', $res->json('purchase.funding'));
        $this->assertSame(0.2, (float) $res->json('grams_balance'));
        $this->assertSame(1000.0, (float) $res->json('cash_balance'));

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(0.2, (float) $wallet->digi_gold_grams);
        $this->assertSame(1000.0, (float) $wallet->cash_balance);

        $txn = DigiGoldTxn::firstOrFail();
        $this->assertSame('buy_wallet', $txn->source);
        $this->assertSame('gold', $txn->metal);

        // insufficient wallet balance is refused, nothing moves
        $this->postJson('/api/v1/digimarket/buy', ['metal' => 'silver', 'amount' => 5000, 'funding' => 'wallet'])
            ->assertStatus(422);
        $this->assertSame(1, DigiGoldPurchase::count());

        // below the minimum is refused
        $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 50, 'funding' => 'wallet'])
            ->assertStatus(422);
    }

    public function test_silver_buy_by_weight_from_wallet(): void
    {
        MemberWallet::find($this->member->id)->update(['cash_balance' => 1000]);
        Sanctum::actingAs($this->member, ['*']);

        // 5 g silver @ ₹100/g = ₹500 debited from cash
        $res = $this->postJson('/api/v1/digimarket/buy', ['metal' => 'silver', 'grams' => 5, 'funding' => 'wallet'])
            ->assertStatus(201);
        $this->assertSame('silver', $res->json('purchase.metal'));

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(5.0, (float) $wallet->digi_silver_grams);
        $this->assertSame(0.0, (float) $wallet->digi_gold_grams);
        $this->assertSame(500.0, (float) $wallet->cash_balance);
    }

    public function test_online_buy_creates_purchase_and_browser_verify_credits_grams_once(): void
    {
        $this->configureGateway();
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_DG_1', 'amount' => 500000, 'currency' => 'INR']),
        ]);

        Sanctum::actingAs($this->member, ['*']);

        $res = $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 5000, 'funding' => 'online'])
            ->assertStatus(201);
        $this->assertSame('created', $res->json('purchase.status'));
        $this->assertSame(0.5, (float) $res->json('purchase.grams'));
        $this->assertStringContainsString('/digigold/', (string) $res->json('pay_url'));
        $this->assertStringContainsString('signature=', (string) $res->json('pay_url'));

        $purchase = DigiGoldPurchase::firstOrFail();
        $this->assertSame('order_DG_1', $purchase->razorpay_order_id);

        // Checkout JS posts back to the web verify route with a valid signature.
        $signature = hash_hmac('sha256', 'order_DG_1|pay_123', 'rzp_test_secret');
        $this->post('/digigold/payment/verify', [
            'purchase_id' => $purchase->id,
            'razorpay_payment_id' => 'pay_123',
            'razorpay_order_id' => 'order_DG_1',
            'razorpay_signature' => $signature,
        ])->assertOk()->assertSee('Payment successful');

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(0.5, (float) $wallet->digi_gold_grams);
        $this->assertSame('paid', $purchase->fresh()->status);

        // verifying again must NOT double-credit
        $this->post('/digigold/payment/verify', [
            'purchase_id' => $purchase->id,
            'razorpay_payment_id' => 'pay_123',
            'razorpay_order_id' => 'order_DG_1',
            'razorpay_signature' => $signature,
        ])->assertOk();
        $this->assertSame(0.5, (float) $wallet->fresh()->digi_gold_grams);
        $this->assertSame(1, DigiGoldTxn::count());

        // the app polls the purchase until paid
        $this->getJson("/api/v1/digimarket/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('purchase.status', 'paid')
            ->assertJsonPath('grams_balance', 0.5);
    }

    public function test_bad_signature_marks_purchase_failed_and_credits_nothing(): void
    {
        $this->configureGateway();
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_DG_2', 'amount' => 100000, 'currency' => 'INR']),
        ]);

        Sanctum::actingAs($this->member, ['*']);
        $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 1000, 'funding' => 'online'])->assertStatus(201);
        $purchase = DigiGoldPurchase::firstOrFail();

        $this->post('/digigold/payment/verify', [
            'purchase_id' => $purchase->id,
            'razorpay_payment_id' => 'pay_bad',
            'razorpay_order_id' => 'order_DG_2',
            'razorpay_signature' => 'not-a-real-signature',
        ])->assertOk()->assertSee('Payment not completed');

        $this->assertSame('failed', $purchase->fresh()->status);
        $this->assertSame(0.0, (float) MemberWallet::find($this->member->id)->digi_gold_grams);
        $this->assertSame(0, DigiGoldTxn::count());
    }

    public function test_webhook_settles_a_purchase_the_browser_never_verified(): void
    {
        $this->configureGateway();
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_DG_3', 'amount' => 200000, 'currency' => 'INR']),
        ]);

        $purchase = app(DigiMarketService::class)->beginPurchase($this->member, 'gold', 2000, 'online');

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_wh_1', 'order_id' => 'order_DG_3', 'method' => 'upi']]],
        ]);
        $signature = hash_hmac('sha256', $body, 'whsec_test');

        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame(0.2, (float) MemberWallet::find($this->member->id)->digi_gold_grams);

        // replayed webhook must not double-credit
        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        $this->assertSame(0.2, (float) MemberWallet::find($this->member->id)->digi_gold_grams);
        $this->assertSame(1, DigiGoldTxn::count());
    }

    public function test_withdraw_converts_metal_to_cash_minus_configured_platform_fee(): void
    {
        // admin sets a 2% platform fee on Commission Setup
        Setting::create(['group' => 'digi_market', 'key' => 'platform_fee_pct', 'value' => '2', 'type' => 'float']);
        MemberWallet::find($this->member->id)->update(['digi_gold_grams' => 1.0, 'cash_balance' => 0]);
        Sanctum::actingAs($this->member, ['*']);

        // withdraw 0.5 g @ ₹10,000/g = ₹5,000 − 2% fee (₹100) = ₹4,900 to cash
        $res = $this->postJson('/api/v1/digimarket/withdraw', ['metal' => 'gold', 'grams' => 0.5])
            ->assertStatus(201)
            ->assertJsonPath('txn.value', 5000)
            ->assertJsonPath('txn.fee', 100)
            ->assertJsonPath('txn.net', 4900);
        $this->assertSame(0.5, (float) $res->json('grams_balance'));

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(0.5, (float) $wallet->digi_gold_grams);
        $this->assertSame(4900.0, (float) $wallet->cash_balance);

        $txn = DigiGoldTxn::where('source', 'withdraw')->firstOrFail();
        $this->assertSame('debit', $txn->type);
        $this->assertSame(100.0, (float) $txn->fee);
        $this->assertSame(0.5, (float) $txn->balance_after);

        // more than the holding is refused, nothing moves
        $this->postJson('/api/v1/digimarket/withdraw', ['metal' => 'gold', 'grams' => 2])
            ->assertStatus(422);
        $this->assertSame(4900.0, (float) $wallet->fresh()->cash_balance);

        // a metal with no holding is refused
        $this->postJson('/api/v1/digimarket/withdraw', ['metal' => 'silver', 'amount' => 500])
            ->assertStatus(422);
    }

    /** Board 2026-08-23 "loophole": a ₹ preset (buy chip) submitted as a withdraw must die at the holding. */
    public function test_withdraw_by_amount_beyond_holding_is_refused_for_both_metals(): void
    {
        MemberWallet::find($this->member->id)->update(['digi_gold_grams' => 0.01, 'digi_silver_grams' => 0.5, 'cash_balance' => 0]);
        Sanctum::actingAs($this->member, ['*']);

        // 0.01 g gold @ ₹10,000/g = ₹100 held; asking for ₹500 is refused
        $this->postJson('/api/v1/digimarket/withdraw', ['metal' => 'gold', 'amount' => 500])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient Digi Gold — you need 0.0500 g, available 0.0100 g.']);
        $this->postJson('/api/v1/digimarket/withdraw', ['metal' => 'silver', 'amount' => 5000])
            ->assertStatus(422);

        $wallet = MemberWallet::find($this->member->id);
        $this->assertSame(0.0, (float) $wallet->cash_balance);
        $this->assertSame(0.01, (float) $wallet->digi_gold_grams);
        $this->assertSame(0.5, (float) $wallet->digi_silver_grams);
    }

    public function test_scan_and_pay_routes_are_gone(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/member/wallet/pay', ['device_uuid' => '00000000-0000-0000-0000-000000000000', 'amount' => 100])
            ->assertStatus(404);
        $this->getJson('/api/v1/member/wallet/payments')->assertStatus(404);
    }
}
