<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\StorefrontDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(StorefrontDemoSeeder::class);
    }

    public function test_public_pages_render(): void
    {
        foreach (['/', '/shop', '/blog', '/news', '/about', '/services', '/faq', '/stores', '/contact', '/cart', '/page/privacy-policy'] as $url) {
            $this->get($url)->assertSuccessful();
        }
    }

    public function test_product_page_renders(): void
    {
        $product = Product::where('is_active', true)->firstOrFail();
        $this->get('/product/' . $product->id)->assertSuccessful();
    }

    public function test_cart_add_and_checkout_flow(): void
    {
        $product = Product::where('is_active', true)->whereNotNull('base_price')->firstOrFail();

        $this->post('/cart/add/' . $product->id, ['qty' => 2])->assertRedirect('/cart');
        $this->get('/cart')->assertSuccessful()->assertSee($product->name['en']);

        $this->post('/checkout', [
            'customer_name' => 'Test Buyer', 'email' => 'buyer@example.com', 'phone' => '9000000000',
            'address' => '1 Test St', 'city' => 'Chennai', 'pincode' => '600001', 'country' => 'IN',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', ['customer_name' => 'Test Buyer', 'status' => 'pending']);
        $this->assertDatabaseHas('order_items', ['name' => $product->name['en'], 'qty' => 2]);
    }

    public function test_currency_switch(): void
    {
        $this->get('/currency?code=USD')->assertRedirect();
        $this->assertEquals('USD', session('currency'));
    }
}
