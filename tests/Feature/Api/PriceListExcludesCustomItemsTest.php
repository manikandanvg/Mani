<?php

namespace Tests\Feature\Api;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Services\CustomizeOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The app's Tools → Price List must never show the system items that carry customized-order pieces. */
class PriceListExcludesCustomItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_order_system_items_are_hidden_from_the_price_list(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        CatalogProduct::create(['code' => 'G1', 'name' => ['en' => 'Gold coin 1 g'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        CustomizeOrderService::customProduct('gold');
        CustomizeOrderService::customProduct('silver');

        $names = collect($this->getJson('/api/v1/price-list')->assertSuccessful()->json('data'))->pluck('name')->all();

        $this->assertContains('Gold coin 1 g', $names);
        $this->assertNotContains('Custom Order — Gold', $names);
        $this->assertNotContains('Custom Order — Silver', $names);
    }
}
