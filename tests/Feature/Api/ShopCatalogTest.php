<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\LiveRate;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — public shop catalog (/api/v1 products + categories).
 */
class ShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Category $defaultCategory;

    protected function setUp(): void
    {
        parent::setUp();
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->defaultCategory = Category::create(['name' => ['en' => 'Default'], 'slug' => 'default', 'domain' => 'ecommerce', 'is_active' => true]);
    }

    protected function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'code' => 'P' . random_int(1000, 9999),
            'category_id' => $this->defaultCategory->id,
            'name' => ['en' => 'Gold Ring'],
            'description' => ['en' => 'A fine gold ring.'],
            'material' => 'gold',
            'weight' => 10,
            'purity' => '22K',
            'gst_pct' => 3,
            'making_charge_pct' => 10,
            'stock_qty' => 5,
            'is_active' => true,
        ], $attrs));
    }

    public function test_products_list_is_public_and_priced(): void
    {
        $this->product(['weight' => 10, 'making_charge_pct' => 10, 'gst_pct' => 3]);

        $res = $this->getJson('/api/v1/products')->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'code', 'name', 'material', 'price' => ['total', 'subtotal', 'gst', 'rate_based', 'has_rate']]],
                'meta' => ['current_page', 'total'],
            ]);

        // Price = 10g × live gold rate + 10% making, then +3% GST. Compute from the rate the
        // endpoint actually uses (LiveRate::latestFor memoizes per process, so reuse it).
        $rate = (float) LiveRate::latestFor('IN')->gold;
        $metal = round(10 * $rate, 2);
        $subtotal = round($metal + round($metal * 10 / 100, 2), 2);
        $total = round($subtotal + round($subtotal * 3 / 100, 2), 2);

        $this->assertEquals($subtotal, $res->json('data.0.price.subtotal'));
        $this->assertEquals($total, $res->json('data.0.price.total'));
        $this->assertTrue($res->json('data.0.price.has_rate'));
    }

    public function test_inactive_products_are_hidden(): void
    {
        $this->product(['is_active' => true, 'code' => 'ACTIVE1']);
        $this->product(['is_active' => false, 'code' => 'HIDDEN1']);

        $res = $this->getJson('/api/v1/products')->assertOk();
        $codes = collect($res->json('data'))->pluck('code');
        $this->assertContains('ACTIVE1', $codes);
        $this->assertNotContains('HIDDEN1', $codes);
    }

    public function test_filter_by_material_and_search_by_code(): void
    {
        $this->product(['material' => 'gold', 'code' => 'GOLD9']);
        $this->product(['material' => 'silver', 'code' => 'SILV9', 'weight' => 50]);

        $gold = $this->getJson('/api/v1/products?material=gold')->assertOk();
        $this->assertEquals(['GOLD9'], collect($gold->json('data'))->pluck('code')->all());

        $byCode = $this->getJson('/api/v1/products?q=SILV')->assertOk();
        $this->assertEquals(['SILV9'], collect($byCode->json('data'))->pluck('code')->all());
    }

    public function test_filter_by_category_matches_category_or_subcategory(): void
    {
        $cat = Category::create(['name' => ['en' => 'Rings'], 'slug' => 'rings', 'domain' => 'ecommerce', 'is_active' => true]);
        $this->product(['category_id' => $cat->id, 'code' => 'INCAT']);
        $this->product(['code' => 'OUTCAT']);

        $res = $this->getJson("/api/v1/products?category={$cat->id}")->assertOk();
        $this->assertEquals(['INCAT'], collect($res->json('data'))->pluck('code')->all());
    }

    public function test_product_detail_includes_images_and_breakup(): void
    {
        $cat = Category::create(['name' => ['en' => 'Rings'], 'slug' => 'rings', 'domain' => 'ecommerce', 'is_active' => true]);
        $product = $this->product(['category_id' => $cat->id]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/ring.jpg', 'sort' => 1]);

        $res = $this->getJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'description', 'category' => ['id', 'name'], 'images', 'price_breakup' => ['metal_value', 'making', 'total']],
            ]);
        $this->assertEquals('Rings', $res->json('data.category.name'));
        $this->assertStringContainsString('products/ring.jpg', $res->json('data.images.0'));
    }

    public function test_inactive_product_detail_is_404(): void
    {
        $product = $this->product(['is_active' => false]);
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_localised_name_respects_lang_param(): void
    {
        $this->product(['name' => ['en' => 'Gold Ring', 'ta' => 'தங்க மோதிரம்'], 'code' => 'LANG1']);

        $en = $this->getJson('/api/v1/products?q=LANG1&lang=en')->json('data.0.name');
        $ta = $this->getJson('/api/v1/products?q=LANG1&lang=ta')->json('data.0.name');
        $this->assertEquals('Gold Ring', $en);
        $this->assertEquals('தங்க மோதிரம்', $ta);
    }

    public function test_categories_returns_active_ecommerce_roots_with_children(): void
    {
        $root = Category::create(['name' => ['en' => 'Gold'], 'slug' => 'gold', 'domain' => 'ecommerce', 'is_active' => true, 'sort' => 1]);
        Category::create(['name' => ['en' => 'Chains'], 'slug' => 'chains', 'domain' => 'ecommerce', 'parent_id' => $root->id, 'is_active' => true, 'sort' => 1]);
        Category::create(['name' => ['en' => 'Inactive'], 'slug' => 'x', 'domain' => 'ecommerce', 'is_active' => false]);
        Category::create(['name' => ['en' => 'TradeOnly'], 'slug' => 'trade-only', 'domain' => 'trade', 'is_active' => true]);

        $res = $this->getJson('/api/v1/categories')->assertOk();
        $data = collect($res->json('data'));
        $names = $data->pluck('name');
        $this->assertContains('Gold', $names);
        $this->assertNotContains('Inactive', $names);    // inactive excluded
        $this->assertNotContains('TradeOnly', $names);   // non-ecommerce domain excluded

        $gold = $data->firstWhere('name', 'Gold');
        $this->assertEquals('Chains', collect($gold['children'])->pluck('name')->first());
    }
}
