<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\Stock\OpeningStockImporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Board 2026-10-31 (common 2): opening stock from the branches' Excel sheet. */
class OpeningStockImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_long_csv_sets_stock_and_records_an_opening_movement(): void
    {
        $branch = Branch::create(['name' => 'Dealer S', 'country' => 'IN', 'is_active' => true]);
        $gold = CatalogProduct::create(['code' => 'G8', 'name' => ['en' => '8 g Gold'], 'material' => 'gold', 'default_weight' => 8, 'is_active' => true]);
        $silver = CatalogProduct::create(['code' => 'S10', 'name' => ['en' => '10 g Silver'], 'material' => 'silver', 'default_weight' => 10, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 3]);

        $csv = tempnam(sys_get_temp_dir(), 'open') . '.csv';
        file_put_contents($csv, "Branch,Product,Qty,Opening\n{$branch->id} - Dealer S,G8,12,5\nDealer S,10 g Silver,40,\nNowhere,G8,1,\nDealer S,ZZZ,1,\n");

        $imp = app(OpeningStockImporter::class)->import($csv, dryRun: true);
        $this->assertSame(2, $imp->applied);
        $this->assertCount(2, $imp->errors);
        $this->assertSame(3.0, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $gold->id)->value('quantity'), 'dry run writes nothing');

        $imp = app(OpeningStockImporter::class)->import($csv);
        $this->assertSame(2, $imp->applied);
        $g = Stock::where('branch_id', $branch->id)->where('catalog_product_id', $gold->id)->first();
        $s = Stock::where('branch_id', $branch->id)->where('catalog_product_id', $silver->id)->first();
        $this->assertSame(12.0, (float) $g->quantity);
        $this->assertSame(5.0, (float) $g->min_qty);
        $this->assertSame(40.0, (float) $s->quantity);
        $this->assertSame(40.0, (float) $s->min_qty, 'opening level defaults to the imported quantity');
        $this->assertSame(9.0, (float) StockMovement::where('ref_type', 'opening')->where('catalog_product_id', $gold->id)->value('qty_change'));
    }

    public function test_wide_template_round_trips(): void
    {
        $branch = Branch::create(['name' => 'Dealer W', 'country' => 'IN', 'is_active' => true]);
        $gold = CatalogProduct::create(['code' => 'G8', 'name' => ['en' => '8 g Gold'], 'material' => 'gold', 'default_weight' => 8, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 7]);

        $xlsx = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        app(OpeningStockImporter::class)->template($xlsx, $branch->id);
        $this->assertFileExists($xlsx);

        // the template re-imported sets exactly what it shows (7) → no movement
        $imp = app(OpeningStockImporter::class)->import($xlsx);
        $this->assertSame([], $imp->errors);
        $this->assertSame(1, $imp->applied);
        $this->assertSame(7.0, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $gold->id)->value('quantity'));
        $this->assertSame(0, StockMovement::where('ref_type', 'opening')->count());
    }
}
