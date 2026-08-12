<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile checkout payment: unpaid orders expose a browser pay_url when the gateway
 * is configured, and the payment.captured webhook settles orders whose buyer never
 * returned from Razorpay Checkout to the verify page.
 */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->member = Member::create([
            'member_code' => 'OP1', 'name' => 'Order Payer', 'phone' => '9000000500', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    protected function checkout(): \Illuminate\Testing\TestResponse
    {
        $category = Category::create(['name' => ['en' => 'Default'], 'slug' => 'default', 'domain' => 'ecommerce', 'is_active' => true]);
        $product = Product::create([
            'code' => 'P100', 'category_id' => $category->id, 'name' => ['en' => 'Gold Ring'],
            'material' => 'gold', 'weight' => 10, 'purity' => '22K', 'gst_pct' => 3,
            'making_charge_pct' => 10, 'stock_qty' => 5, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->member, ['*']);

        return $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_name' => 'Order Payer', 'phone' => '9000000500',
            'address' => '1 Main St', 'city' => 'Chennai',
        ]);
    }

    public function test_checkout_returns_pay_url_when_gateway_configured(): void
    {
        config(['services.razorpay.key' => 'rzp_test_key', 'services.razorpay.secret' => 'rzp_test_secret']);

        $res = $this->checkout()->assertOk();

        $this->assertSame('unpaid', $res->json('data.payment_status'));
        $order = Order::firstOrFail();
        $this->assertSame(route('order.pay', $order->order_no), $res->json('data.pay_url'));
    }

    public function test_checkout_pay_url_is_null_when_gateway_not_configured(): void
    {
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);

        $res = $this->checkout()->assertOk();
        $this->assertNull($res->json('data.pay_url'));
    }

    public function test_payment_captured_webhook_settles_the_order(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);

        $order = Order::create([
            'order_no' => 'ORD-000042', 'customer_name' => 'Order Payer', 'phone' => '9000000500',
            'address' => '1 Main St', 'city' => 'Chennai', 'country' => 'IN',
            'currency_code' => 'INR', 'fx_rate' => 1, 'subtotal' => 1000, 'tax' => 30,
            'shipping' => 0, 'total' => 1030, 'status' => 'pending', 'payment_status' => 'unpaid',
            'member_id' => $this->member->id, 'razorpay_order_id' => 'order_WEB_1',
        ]);

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_wh_9', 'order_id' => 'order_WEB_1', 'method' => 'upi']]],
        ]);
        $signature = hash_hmac('sha256', $body, 'whsec_test');

        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->paid_at);

        $payment = Payment::firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('pay_wh_9', $payment->razorpay_payment_id);

        // replay is harmless
        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        $this->assertSame(1, Payment::count());
    }
}
