<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rank;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — member cart checkout + order history. Prices are recomputed server-side.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->category = Category::create(['name' => ['en' => 'Default'], 'slug' => 'default', 'domain' => 'ecommerce', 'is_active' => true]);
        $this->member = Member::create([
            'member_code' => 'MC1', 'name' => 'Buyer', 'phone' => '9000000100', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'code' => 'P' . random_int(1000, 9999), 'category_id' => $this->category->id,
            'name' => ['en' => 'Gold Ring'], 'material' => 'gold', 'weight' => 10, 'purity' => '22K',
            'gst_pct' => 3, 'making_charge_pct' => 10, 'stock_qty' => 5, 'is_active' => true,
        ], $attrs));
    }

    protected function actingAsMember(): void
    {
        Sanctum::actingAs($this->member, ['*']);
    }

    public function test_checkout_requires_auth(): void
    {
        $product = $this->product();
        $this->postJson('/api/v1/checkout', ['items' => [['product_id' => $product->id, 'qty' => 1]]])
            ->assertStatus(401);
    }

    public function test_quote_prices_cart_server_side(): void
    {
        $this->actingAsMember();
        $product = $this->product(['weight' => 10, 'making_charge_pct' => 10, 'gst_pct' => 3]);

        $rate = (float) LiveRate::latestFor('IN')->gold;
        $unit = round(round(10 * $rate, 2) + round(round(10 * $rate, 2) * 10 / 100, 2), 2); // ex-GST subtotal
        $ex = round($unit * 2, 2);
        $tax = round($ex * 3 / 100, 2);

        $res = $this->postJson('/api/v1/checkout/quote', ['items' => [['product_id' => $product->id, 'qty' => 2]]])
            ->assertOk();
        $this->assertEquals($ex, $res->json('subtotal'));
        $this->assertEquals($tax, $res->json('tax'));
        $this->assertEquals(round($ex + $tax, 2), $res->json('total'));
    }

    public function test_checkout_places_order_for_member(): void
    {
        $this->actingAsMember();
        $product = $this->product();

        $res = $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'customer_name' => 'Buyer', 'phone' => '9000000100',
            'address' => '1 Main St', 'city' => 'Trichy', 'pincode' => '620001',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonStructure(['data' => ['order_no', 'total', 'items' => [['product_id', 'qty', 'unit_price', 'line_total']]], 'message']);

        $orderId = $res->json('data.id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'member_id' => $this->member->id, 'currency_code' => 'INR']);
        $this->assertEquals(2, (int) $res->json('data.items.0.qty'));
    }

    public function test_checkout_ignores_client_supplied_prices(): void
    {
        $this->actingAsMember();
        $product = $this->product();

        // Client tries to sneak a price in — server must ignore it and re-price.
        $res = $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1, 'line_total' => 1]],
            'customer_name' => 'Buyer', 'phone' => '9000000100', 'address' => 'x', 'city' => 'y',
        ])->assertOk();

        $this->assertGreaterThan(1, (float) $res->json('data.total'));
    }

    public function test_checkout_rejects_inactive_product(): void
    {
        $this->actingAsMember();
        $product = $this->product(['is_active' => false]);

        $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_name' => 'Buyer', 'phone' => '9000000100', 'address' => 'x', 'city' => 'y',
        ])->assertStatus(422);
    }

    public function test_member_sees_only_their_orders(): void
    {
        $this->actingAsMember();
        $product = $this->product();
        $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_name' => 'Buyer', 'phone' => '9000000100', 'address' => 'x', 'city' => 'y',
        ])->assertOk();

        // Another member's order should not appear.
        $other = Member::create(['member_code' => 'MC2', 'name' => 'Other', 'phone' => '9000000200', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        Order::create(['member_id' => $other->id, 'order_no' => 'ORD-999', 'customer_name' => 'Other', 'phone' => '1', 'address' => 'z', 'city' => 'z', 'currency_code' => 'INR', 'fx_rate' => 1, 'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10, 'status' => 'pending', 'payment_status' => 'unpaid']);

        $res = $this->getJson('/api/v1/orders')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($this->member->id, Order::find($res->json('data.0.id'))->member_id);
    }

    public function test_customer_checkout_scopes_order_and_backfills_profile(): void
    {
        $customer = Customer::create(['phone' => '9000000900']);
        Sanctum::actingAs($customer, ['*']);
        $product = $this->product();

        $res = $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_name' => 'Public Shopper', 'phone' => '9000000900',
            'email' => 'shop@example.com', 'address' => '5 Market Rd', 'city' => 'Madurai', 'pincode' => '625001',
        ])->assertOk();

        $orderId = $res->json('data.id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'customer_id' => $customer->id, 'member_id' => null]);

        // First checkout backfills the empty customer profile.
        $customer->refresh();
        $this->assertEquals('Public Shopper', $customer->name);
        $this->assertEquals('Madurai', $customer->city);

        // The customer sees their own order; a member's order is not visible to them.
        $member = Member::create(['member_code' => 'MX1', 'name' => 'M', 'phone' => '9111111111', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        Order::create(['member_id' => $member->id, 'order_no' => 'ORD-555', 'customer_name' => 'M', 'phone' => '1', 'address' => 'z', 'city' => 'z', 'currency_code' => 'INR', 'fx_rate' => 1, 'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10, 'status' => 'pending', 'payment_status' => 'unpaid']);

        $list = $this->getJson('/api/v1/orders')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertEquals($orderId, $list->json('data.0.id'));
    }

    public function test_order_detail_is_scoped_to_member(): void
    {
        $this->actingAsMember();
        $other = Member::create(['member_code' => 'MC3', 'name' => 'Other', 'phone' => '9000000300', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        $foreign = Order::create(['member_id' => $other->id, 'order_no' => 'ORD-777', 'customer_name' => 'Other', 'phone' => '1', 'address' => 'z', 'city' => 'z', 'currency_code' => 'INR', 'fx_rate' => 1, 'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10, 'status' => 'pending', 'payment_status' => 'unpaid']);

        $this->getJson("/api/v1/orders/{$foreign->id}")->assertNotFound();
    }
}
