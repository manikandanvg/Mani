<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Stock;
use App\Models\StockReturn;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\StockReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branch → HQ stock returns credit the branch Digi cash wallet; the wallet pays for
 * stock orders (items 6 + 7, board spec 2026-07-28).
 */
class StockReturnDigiCashTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $branch;

    protected CatalogProduct $gold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq = Branch::create(['id' => 1, 'name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $this->branch = Branch::create(['name' => 'Ret Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $this->gold = CatalogProduct::create([
            'code' => 'SRG', 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 0,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 0,
            'hsn_code' => '71082000', 'is_active' => true,
        ]);
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        Stock::create(['branch_id' => $this->branch->id, 'catalog_product_id' => $this->gold->id, 'quantity' => 100]);
    }

    public function test_approved_return_moves_stock_and_credits_digi_cash(): void
    {
        $return = app(StockReturnService::class)->submit([
            'branch_id' => $this->branch->id,
            'lines' => [['catalog_product_id' => $this->gold->id, 'weight' => 10]],
        ]);

        $this->assertEquals('pending', $return->status);
        $this->assertEquals(50000.0, (float) $return->total_amount);   // 10g × ₹5,000, no charges/GST
        $this->assertEquals(0.0, (float) $this->branch->fresh()->digi_cash_balance);   // nothing until approval

        app(StockReturnService::class)->approve($return);

        $this->assertEquals('approved', $return->fresh()->status);
        $this->assertEquals(90, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->gold->id)->value('quantity'));
        $this->assertEquals(10, (float) Stock::where('branch_id', 1)->where('catalog_product_id', $this->gold->id)->value('quantity'));
        $this->assertEquals(50000.0, (float) $this->branch->fresh()->digi_cash_balance);
    }

    public function test_cannot_return_more_than_branch_holds(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(StockReturnService::class)->submit([
            'branch_id' => $this->branch->id,
            'lines' => [['catalog_product_id' => $this->gold->id, 'weight' => 500]],
        ]);
    }

    public function test_digi_cash_pays_for_stock_order_and_refunds_on_reject(): void
    {
        $this->branch->update(['digi_cash_balance' => 50000]);
        $user = User::create(['name' => 'B', 'email' => 'b@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $this->branch->id]);

        // Order 5g gold (₹25,000) paid from the wallet.
        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $this->branch->id, 'requested_by' => $user->id,
            'payment_type' => 'digi_cash',
            'lines' => [['catalog_product_id' => $this->gold->id, 'weight' => 5]],
        ]);

        $this->assertEquals(25000.0, (float) $request->grand_total);
        $this->assertEquals(25000.0, (float) $this->branch->fresh()->digi_cash_balance);   // charged on submit

        // Rejection refunds the wallet.
        app(BranchOrderService::class)->reject($request->fresh(), $user->id);
        $this->assertEquals(50000.0, (float) $this->branch->fresh()->digi_cash_balance);
    }

    public function test_insufficient_digi_cash_blocks_the_order(): void
    {
        $this->branch->update(['digi_cash_balance' => 100]);
        $user = User::create(['name' => 'B2', 'email' => 'b2@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $this->branch->id]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BranchOrderService::class)->submit([
            'branch_id' => $this->branch->id, 'requested_by' => $user->id,
            'payment_type' => 'digi_cash',
            'lines' => [['catalog_product_id' => $this->gold->id, 'weight' => 5]],
        ]);
    }
}
