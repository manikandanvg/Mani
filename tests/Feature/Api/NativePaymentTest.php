<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\DigiGoldPurchase;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Native in-app payments (2026-08-24): the app runs Razorpay's native Checkout
 * SDK, so the API hands out Checkout options (intent) and settles from the
 * SDK result (verify) over JSON — no browser pay page in the app flow.
 */
class NativePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->member = Member::create([
            'member_code' => 'NP1', 'name' => 'Native Payer', 'phone' => '9000000600', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $this->member->id]);
    }

    protected function configureGateway(): void
    {
        config(['services.razorpay.key' => 'rzp_test_key', 'services.razorpay.secret' => 'rzp_test_secret']);
    }

    protected function fakeGateway(string $orderId): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => $orderId, 'amount' => 1, 'currency' => 'INR']),
            'api.razorpay.com/v1/payments/*' => Http::response(['method' => 'upi', 'email' => null, 'contact' => '9000000600']),
        ]);
    }

    protected function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, 'rzp_test_secret');
    }

    protected function placeOrder(): Order
    {
        $category = Category::create(['name' => ['en' => 'Default'], 'slug' => 'default', 'domain' => 'ecommerce', 'is_active' => true]);
        $product = Product::create([
            'code' => 'P200', 'category_id' => $category->id, 'name' => ['en' => 'Gold Ring'],
            'material' => 'gold', 'weight' => 10, 'purity' => '22K', 'gst_pct' => 3,
            'making_charge_pct' => 10, 'stock_qty' => 5, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->member, ['*']);
        $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_name' => 'Native Payer', 'phone' => '9000000600',
            'address' => '1 Main St', 'city' => 'Chennai',
        ])->assertOk();

        return Order::firstOrFail();
    }

    public function test_order_is_payable_and_intent_returns_checkout_options(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_NATIVE_1');
        $order = $this->placeOrder();

        $this->getJson('/api/v1/orders/' . $order->id)->assertOk()->assertJsonPath('data.payable', true);

        $res = $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertOk();
        $this->assertSame('unpaid', $res->json('status'));
        $this->assertSame('rzp_test_key', $res->json('checkout.key_id'));
        $this->assertSame('order_NATIVE_1', $res->json('checkout.razorpay_order_id'));
        $this->assertSame((int) round((float) $order->total * 100), $res->json('checkout.amount_paise'));
        $this->assertSame('9000000600', $res->json('checkout.prefill.contact'));
        $this->assertSame('order_NATIVE_1', $order->refresh()->razorpay_order_id);

        // A second intent reuses the gateway order rather than creating another.
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertOk()
            ->assertJsonPath('checkout.razorpay_order_id', 'order_NATIVE_1');
        Http::assertSentCount(1);
    }

    public function test_verify_settles_the_order_once_and_replays_are_harmless(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_NATIVE_2');
        $order = $this->placeOrder();
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertOk();

        $body = [
            'razorpay_order_id' => 'order_NATIVE_2',
            'razorpay_payment_id' => 'pay_N2',
            'razorpay_signature' => $this->signature('order_NATIVE_2', 'pay_N2'),
        ];
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/verify', $body)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.payable', false);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $payment = Payment::firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('upi', $payment->method);

        $this->postJson('/api/v1/orders/' . $order->id . '/payment/verify', $body)->assertOk();
        $this->assertSame(1, Payment::count());

        // Already paid: intent reports it instead of minting Checkout options.
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonMissingPath('checkout');
    }

    public function test_bad_signature_is_refused_and_nothing_is_marked_paid(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_NATIVE_3');
        $order = $this->placeOrder();
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertOk();

        $this->postJson('/api/v1/orders/' . $order->id . '/payment/verify', [
            'razorpay_order_id' => 'order_NATIVE_3',
            'razorpay_payment_id' => 'pay_N3',
            'razorpay_signature' => 'forged',
        ])->assertStatus(422);

        $this->assertSame('unpaid', $order->refresh()->payment_status);
        $this->assertSame('failed', Payment::firstOrFail()->status);
    }

    public function test_intent_and_verify_are_owner_only(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_NATIVE_4');
        $order = $this->placeOrder();

        $other = Member::create([
            'member_code' => 'NP2', 'name' => 'Someone Else', 'phone' => '9000000601', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active',
        ]);
        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertStatus(404);
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/verify', [
            'razorpay_order_id' => 'order_NATIVE_4', 'razorpay_payment_id' => 'x', 'razorpay_signature' => 'y',
        ])->assertStatus(404);
    }

    public function test_order_is_not_payable_when_gateway_is_off(): void
    {
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);
        $order = $this->placeOrder();

        $this->getJson('/api/v1/orders/' . $order->id)->assertOk()->assertJsonPath('data.payable', false);
        $this->postJson('/api/v1/orders/' . $order->id . '/payment/intent')->assertStatus(422);
    }

    public function test_digimarket_online_buy_returns_checkout_and_native_verify_credits_grams(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_DG_N1');
        Sanctum::actingAs($this->member, ['*']);

        $res = $this->postJson('/api/v1/digimarket/buy', ['metal' => 'gold', 'amount' => 3000, 'funding' => 'online'])
            ->assertStatus(201);
        $this->assertSame('created', $res->json('purchase.status'));
        $this->assertSame('rzp_test_key', $res->json('payment.key_id'));
        $this->assertSame('order_DG_N1', $res->json('payment.razorpay_order_id'));
        $this->assertSame(300000, $res->json('payment.amount_paise'));
        $this->assertArrayNotHasKey('pay_url', $res->json());
        $purchaseId = $res->json('purchase.id');

        // Re-fetching an unpaid purchase still offers the Checkout options (retry).
        $this->getJson('/api/v1/digimarket/purchases/' . $purchaseId)->assertOk()
            ->assertJsonPath('payment.razorpay_order_id', 'order_DG_N1');

        // Wrong gateway order → refused, nothing credited.
        $this->postJson('/api/v1/digimarket/purchases/' . $purchaseId . '/verify', [
            'razorpay_order_id' => 'order_OTHER', 'razorpay_payment_id' => 'pay_DG1',
            'razorpay_signature' => $this->signature('order_OTHER', 'pay_DG1'),
        ])->assertStatus(422);

        $verify = $this->postJson('/api/v1/digimarket/purchases/' . $purchaseId . '/verify', [
            'razorpay_order_id' => 'order_DG_N1', 'razorpay_payment_id' => 'pay_DG1',
            'razorpay_signature' => $this->signature('order_DG_N1', 'pay_DG1'),
        ])->assertOk();
        $this->assertSame('paid', $verify->json('purchase.status'));
        $this->assertSame(0.5, (float) $verify->json('grams_balance'));   // ₹3000 / ₹6000 per g
        $this->assertArrayNotHasKey('payment', $verify->json());

        // Paid: no more Checkout options, and a replay stays idempotent.
        $this->getJson('/api/v1/digimarket/purchases/' . $purchaseId)->assertOk()->assertJsonMissingPath('payment');
        $this->postJson('/api/v1/digimarket/purchases/' . $purchaseId . '/verify', [
            'razorpay_order_id' => 'order_DG_N1', 'razorpay_payment_id' => 'pay_DG1',
            'razorpay_signature' => $this->signature('order_DG_N1', 'pay_DG1'),
        ])->assertOk();
        $this->assertSame(0.5, (float) MemberWallet::find($this->member->id)->digi_gold_grams);
        $this->assertSame('paid', DigiGoldPurchase::find($purchaseId)->status);
    }

    public function test_digimarket_verify_with_forged_signature_marks_purchase_failed(): void
    {
        $this->configureGateway();
        $this->fakeGateway('order_DG_N2');
        Sanctum::actingAs($this->member, ['*']);

        $purchaseId = $this->postJson('/api/v1/digimarket/buy', ['metal' => 'silver', 'amount' => 1000, 'funding' => 'online'])
            ->assertStatus(201)->json('purchase.id');

        $this->postJson('/api/v1/digimarket/purchases/' . $purchaseId . '/verify', [
            'razorpay_order_id' => 'order_DG_N2', 'razorpay_payment_id' => 'pay_DG2', 'razorpay_signature' => 'forged',
        ])->assertStatus(422)->assertJsonPath('purchase.status', 'failed');

        $this->assertSame(0.0, (float) MemberWallet::find($this->member->id)->digi_silver_grams);
    }
}
