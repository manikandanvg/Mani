<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minimum stock level (board 2026-08-13): a branch's product is "low" when its
 * quantity falls to or below the minimum it set, which drives the row highlight,
 * the Low-stock filter and the alert popup on the Stock screen.
 */
class LowStockTest extends TestCase
{
    use RefreshDatabase;

    protected function stock(float $qty, ?float $min): Stock
    {
        $branch = Branch::firstOrCreate(['name' => 'Low Stock Branch'], ['country' => 'IN', 'is_active' => true]);
        $product = CatalogProduct::create([
            'code' => 'LS' . fake()->unique()->numberBetween(1000, 9999),
            'name' => ['en' => 'Test Chain'],
            'material' => 'gold',
        ]);

        return Stock::create([
            'branch_id' => $branch->id,
            'catalog_product_id' => $product->id,
            'quantity' => $qty,
            'min_qty' => $min,
        ]);
    }

    public function test_stock_is_low_at_or_below_the_minimum(): void
    {
        $this->assertTrue($this->stock(5, 10)->is_low, 'below minimum should be low');
        $this->assertTrue($this->stock(10, 10)->is_low, 'exactly at the minimum should be low');
        $this->assertFalse($this->stock(11, 10)->is_low, 'above minimum should not be low');
    }

    public function test_no_minimum_means_never_low(): void
    {
        $this->assertFalse($this->stock(0, null)->is_low);
    }

    public function test_hq_adjustment_logs_an_audited_movement(): void
    {
        $stock = $this->stock(100, 10);

        // Mirrors the "Adjust stock" action: correct the count to the physical
        // figure and log the difference so the ledger still reconciles.
        $new = 82.5;
        $change = round($new - (float) $stock->quantity, 4);

        $stock->update(['quantity' => $new]);
        \App\Models\StockMovement::create([
            'branch_id' => $stock->branch_id,
            'catalog_product_id' => $stock->catalog_product_id,
            'type' => 'adjustment',
            'qty_change' => $change,
            'balance_after' => $new,
            'ref_type' => 'hq_adjustment',
            'note' => 'Physical count',
            'moved_on' => now()->toDateString(),
        ]);

        $move = \App\Models\StockMovement::where('ref_type', 'hq_adjustment')->firstOrFail();
        $this->assertSame('adjustment', $move->type);
        $this->assertEquals(-17.5, (float) $move->qty_change, 'the difference is what gets logged');
        $this->assertEquals(82.5, (float) $move->balance_after);
        $this->assertSame('Physical count', $move->note);
        $this->assertEquals(82.5, (float) $stock->fresh()->quantity);
    }

    public function test_low_scope_returns_only_breached_rows(): void
    {
        $low = $this->stock(2, 10);
        $ok = $this->stock(50, 10);
        $unset = $this->stock(0, null);

        $ids = Stock::low()->pluck('id')->all();

        $this->assertContains($low->id, $ids);
        $this->assertNotContains($ok->id, $ids);
        $this->assertNotContains($unset->id, $ids, 'a product with no minimum must never be flagged');
    }
}
