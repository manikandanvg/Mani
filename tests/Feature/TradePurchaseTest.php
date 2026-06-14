<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\TradePurchaseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradePurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class); // seeds Head Office branch + categories
    }

    protected function product(): CatalogProduct
    {
        return CatalogProduct::create([
            'code' => 'G100MG',
            'name' => ['en' => '100mg Gold Coin'],
            'material' => 'gold',
            'default_purity' => '22K',
            'gst_pct' => 3,
            'is_active' => true,
        ]);
    }

    public function test_purchase_creates_lines_and_totals(): void
    {
        $branch = Branch::firstOrFail();
        $p = $this->product();

        $purchase = app(TradePurchaseService::class)->record([
            'branch_id' => $branch->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [
                ['catalog_product_id' => $p->id, 'material' => 'gold', 'weight' => 20, 'rate' => 7000, 'gst_pct' => 3],
            ],
        ]);

        // base 20*7000 = 140000; gst 3% = 4200; grand 144200
        $this->assertEquals(140000, $purchase->gross_total);
        $this->assertEquals(4200, $purchase->gst_total);
        $this->assertEquals(144200, $purchase->grand_total);
        $this->assertCount(1, $purchase->lines);
        $this->assertEquals(144200, $purchase->lines->first()->line_total);
    }

    public function test_stock_accumulates_across_purchases(): void
    {
        $branch = Branch::firstOrFail();
        $p = $this->product();
        $svc = app(TradePurchaseService::class);

        $svc->record(['branch_id' => $branch->id, 'lines' => [
            ['catalog_product_id' => $p->id, 'material' => 'gold', 'weight' => 20, 'rate' => 7000, 'gst_pct' => 3],
        ]]);
        $svc->record(['branch_id' => $branch->id, 'lines' => [
            ['catalog_product_id' => $p->id, 'material' => 'gold', 'weight' => 100, 'rate' => 7100, 'gst_pct' => 3],
        ]]);

        $stock = Stock::where('branch_id', $branch->id)->where('catalog_product_id', $p->id)->firstOrFail();
        $this->assertEquals(120, $stock->quantity);          // 20 + 100
        $this->assertEquals(7100, $stock->last_rate);

        $movements = StockMovement::where('catalog_product_id', $p->id)->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertEquals(20, $movements[0]->balance_after);
        $this->assertEquals(120, $movements[1]->balance_after);
    }
}
