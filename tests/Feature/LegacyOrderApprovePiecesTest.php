<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOrderLine;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BranchOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User 2026-11-16 (LEG-28945): an old-system order line counts PIECES — "100 mg Gold" × 6 puts
 * six coins into the branch (creating the stock row when it has none), never 6 g = 60 coins;
 * "8 g Gold" × 1 is one coin, never 1 g = 1/8 of a coin.
 */
class LegacyOrderApprovePiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function order(Branch $branch, string $source, array $lines): BranchOrderRequest
    {
        $order = BranchOrderRequest::create([
            'request_no' => strtoupper(substr($source, 0, 3)) . '-' . random_int(10000, 99999), 'source' => $source,
            'branch_id' => $branch->id, 'no_of_items' => count($lines), 'cross_total' => 1000, 'gst_total' => 30,
            'grand_total' => 1030, 'payment_type' => 'others', 'status' => 'pending',
        ]);
        foreach ($lines as [$product, $weight, $rate]) {
            BranchOrderLine::create([
                'order_request_id' => $order->id, 'catalog_product_id' => $product->id, 'material' => $product->material,
                'description' => $product->code, 'weight' => $weight, 'rate' => $rate, 'making_charge_pct' => 0,
                'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'line_total' => $weight * $rate,
            ]);
        }

        return $order->fresh('lines');
    }

    public function test_a_legacy_order_adds_its_piece_count_to_the_branch_stock(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
        $branch = Branch::create(['name' => 'Legacy Buyer', 'country' => 'IN', 'level' => 'district', 'is_active' => true]);
        $mk = fn (string $code, string $name) => CatalogProduct::create(['code' => $code, 'name' => ['en' => $name], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        $mg100 = $mk('LG100', '100 mg Gold');
        $g1 = $mk('LG1', '1 g Gold');
        $g8 = $mk('LG8', '8 g Gold');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $g1->id, 'quantity' => 29]);

        $order = $this->order($branch, BranchOrderService::SOURCE_LEGACY, [[$g1, 1, 12730], [$mg100, 6, 1273], [$g8, 1, 101840]]);
        app(BranchOrderService::class)->approve($order, $admin->id);

        $qty = fn (CatalogProduct $p) => (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $p->id)->value('quantity');
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertEquals(30, $qty($g1));
        $this->assertEquals(6, $qty($mg100));     // stock row created, six coins — not 60
        $this->assertEquals(1, $qty($g8));        // one coin — not 0.125
        $this->assertEquals(6, (float) StockMovement::where('ref_type', 'branch_order')->where('ref_id', $order->id)
            ->where('catalog_product_id', $mg100->id)->value('qty_change'));
        // the per-piece price becomes the per-gram rate the stock row carries
        $this->assertEquals(12730, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $mg100->id)->value('last_rate'));

        // an Order Form order is still entered in grams: 6 g of 100 mg coins = 60 pieces
        $native = $this->order($branch, BranchOrderService::SOURCE_ORDER_FORM, [[$mg100, 6, 12730]]);
        app(BranchOrderService::class)->approve($native, $admin->id);
        $this->assertEquals(66, $qty($mg100));
    }

    /** User 2026-11-16: one restock order per redemption invoice — an old-system LEG- order counts. */
    public function test_a_redemption_invoice_carries_only_one_restock_order(): void
    {
        $branch = Branch::create(['name' => 'Legacy Buyer', 'country' => 'IN', 'level' => 'district', 'is_active' => true]);
        $gold = CatalogProduct::create(['code' => 'LG1', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        $invoice = \App\Models\RedemptionInvoice::create([
            'invoice_no' => '4463', 'invoice_date' => '2026-10-31', 'branch_id' => $branch->id, 'reference_no' => '29635',
            'payment_mode' => 'passed', 'legacy_restock' => 'ordered', 'taxable_total' => 1000, 'grand_total' => 1030,
        ]);
        \App\Models\RedemptionLine::create([
            'redemption_invoice_id' => $invoice->id, 'catalog_product_id' => $gold->id, 'description' => '1 g Gold', 'material' => 'gold',
            'unit_weight' => 1, 'quantity' => 1, 'rate' => 1000, 'gst_pct' => 3, 'amount' => 1000, 'line_total' => 1000,
        ]);

        $legacy = $this->order($branch, BranchOrderService::SOURCE_LEGACY, [[$gold, 1, 1000]]);
        $legacy->update(['redemption_invoice_id' => $invoice->id]);

        // the invoice shows its old-system order, and the order shows its invoice
        $this->assertSame($legacy->id, $invoice->fresh()->restockOrder?->id);
        $this->assertSame('4463', $legacy->fresh()->redemptionInvoice?->invoice_no);

        // the "Stock stage" filter follows the order: pending now, Re-Stocked once approved
        $stage = fn (string $s) => \App\Filament\Resources\RedemptionInvoiceResource::applyRestockStage(\App\Models\RedemptionInvoice::query(), $s)->pluck('id')->all();
        $this->assertSame([$invoice->id], $stage('pending'));
        $this->assertSame([], $stage('restocked'));
        $this->assertSame([], $stage('not_raised'));
        $notRaised = \App\Models\RedemptionInvoice::create([
            'invoice_no' => '4464', 'invoice_date' => '2026-10-31', 'branch_id' => $branch->id,
            'payment_mode' => 'passed', 'legacy_restock' => 'pending', 'taxable_total' => 1000, 'grand_total' => 1030,
        ]);
        $doneInLegacy = \App\Models\RedemptionInvoice::create([
            'invoice_no' => '4465', 'invoice_date' => '2026-10-31', 'branch_id' => $branch->id,
            'payment_mode' => 'passed', 'legacy_restock' => 'approved', 'taxable_total' => 1000, 'grand_total' => 1030,
        ]);
        // a new-system invoice (no legacy_restock at all) with no order yet is "Not raised" too
        $newSystem = \App\Models\RedemptionInvoice::create([
            'invoice_no' => 'RDM-TEST-0001', 'invoice_date' => '2026-11-16', 'branch_id' => $branch->id,
            'payment_mode' => 'pending', 'taxable_total' => 1000, 'grand_total' => 1030,
        ]);
        $this->assertSame([$notRaised->id, $newSystem->id], $stage('not_raised'));
        $this->assertSame([$doneInLegacy->id], $stage('restocked'));

        // no second restock order from the invoice screen …
        try {
            app(BranchOrderService::class)->createFromRedemption($invoice);
            $this->fail('a second restock order was raised for the same redemption invoice');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('already raised', $e->getMessage());
        }

        // … and none by any other road: the database refuses it
        $second = $this->order($branch, BranchOrderService::SOURCE_LEGACY, [[$gold, 1, 1000]]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $second->update(['redemption_invoice_id' => $invoice->id]);
    }
}
