<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Plan;
use App\Models\SaleLine;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Complaint 2026-11-21 (Lord's board, LJW2417736232945 — "ID was billed but no stock reduced
 * from the branch"): a cash line typed as Amount 1 × Price 1,00,000 made the right bill but
 * took ₹1 from the branch cash stock (live INV-B2290-0001 … 0005). A cash line is an amount:
 * the stock falls by the amount however the line was typed, and the bills already raised are
 * corrected by stock:fix-cash-bill-deductions.
 */
class CashBillStockDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected CatalogProduct $cash;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->branch = Branch::create(['name' => 'Dealer Shop', 'country' => 'IN', 'is_active' => true]);
        $this->cash = CatalogProduct::create(['code' => 'CASHX', 'name' => ['en' => 'Cash'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $this->branch->id, 'catalog_product_id' => $this->cash->id, 'quantity' => 600000]);
        $this->plan = Plan::create([
            'code' => 'PSUBX', 'name' => ['en' => 'Sub Dealer'], 'plan_type' => 1, 'type' => 'digital', 'min_value' => 0,
            'allocation_bv' => 100, 'allocation' => 75, 'validity_months' => 24, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 24,
            'billing_margin' => 0, 'is_active' => true,
        ]);
    }

    protected function bill(float $weight, float $rate, string $phone): \App\Models\SalesInvoice
    {
        return app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id,
            'plan_id' => $this->plan->id,
            'customer' => ['name' => 'Buyer ' . $phone, 'phone' => $phone, 'pan' => 'ABCDE1234F'],
            'cart' => [[
                'catalog_product_id' => $this->cash->id, 'material' => 'cash',
                'weight' => $weight, 'rate' => $rate,
                'net_total' => $weight * $rate, 'grand_total' => $weight * $rate,
            ]],
        ]);
    }

    protected function cashStock(): float
    {
        return (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->value('quantity');
    }

    public function test_a_cash_line_typed_as_one_times_the_amount_still_takes_the_amount_from_the_stock(): void
    {
        $invoice = $this->bill(1, 100000, '9000000911');

        $this->assertEquals(100000, $invoice->grand_total);
        $this->assertEquals(500000, $this->cashStock());

        $line = SaleLine::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(100000, $line->qty);
        $this->assertEquals(1, $line->rate);
        $this->assertEquals(100000, $line->line_total);
    }

    public function test_a_cash_line_typed_the_right_way_is_unchanged(): void
    {
        $invoice = $this->bill(500000, 1, '9000000912');

        $this->assertEquals(500000, $invoice->grand_total);
        $this->assertEquals(100000, $this->cashStock());
        $this->assertEquals(500000, SaleLine::where('invoice_id', $invoice->id)->value('qty'));
    }

    /**
     * Major complaint 2026-11-25 (INV-HQ-0604: ₹2.62 Cr of cash stock, ₹112.62 Cr billed): a bill
     * may never sell more than the branch holds — typed either way, and Head Office included.
     */
    public function test_a_bill_above_the_cash_stock_is_refused_and_nothing_is_written(): void
    {
        $before = $this->cashStock();
        $bills = \App\Models\SalesInvoice::count();
        foreach ([[$before + 1, 1], [1, $before + 100000]] as [$weight, $rate]) {
            try {
                $this->bill($weight, $rate, '9000000914');
                $this->fail('A bill above the cash stock went through.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertStringContainsString('Not enough stock at this branch', $e->getMessage());
            }
        }
        $this->assertEquals($before, $this->cashStock());
        $this->assertSame($bills, \App\Models\SalesInvoice::count());
        $this->assertSame(0, StockMovement::where('ref_type', 'sale')->count());

        // exactly the stock is fine, and leaves it at zero — never below
        $this->bill($before, 1, '9000000915');
        $this->assertEquals(0, $this->cashStock());
    }

    public function test_the_command_corrects_bills_already_raised_short_and_only_once(): void
    {
        // a bill as live holds it: line 1 × 1,00,000 and ₹1 taken from the stock
        $invoice = $this->bill(100000, 1, '9000000913');
        SaleLine::where('invoice_id', $invoice->id)->update(['qty' => 1, 'rate' => 100000]);
        $move = StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->firstOrFail();
        $move->update(['qty_change' => -1, 'balance_after' => 599999]);
        Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->update(['quantity' => 599999]);

        // dry run writes nothing
        $this->artisan('stock:fix-cash-bill-deductions')->assertSuccessful();
        $this->assertEquals(599999, $this->cashStock());

        $this->artisan('stock:fix-cash-bill-deductions', ['--apply' => true])->assertSuccessful();
        $this->assertEquals(500000, $this->cashStock());
        $line = SaleLine::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(100000, $line->qty);
        $this->assertEquals(1, $line->rate);
        $this->assertEquals(-100000, (float) StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->sum('qty_change'));

        // a second run finds nothing left to do
        $this->artisan('stock:fix-cash-bill-deductions', ['--apply' => true])->assertSuccessful();
        $this->assertEquals(500000, $this->cashStock());
    }

    /** 2026-11-25: the quantity the stock falls by must be real — a small "amount" can not carry a big bill. */
    public function test_a_cash_line_cannot_be_billed_for_more_than_its_amount_nor_with_a_negative_one(): void
    {
        $send = fn (float $weight, float $value) => app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $this->plan->id,
            'customer' => ['name' => 'Buyer X', 'phone' => '9000000916', 'pan' => 'ABCDE1234F'],
            'cart' => [['catalog_product_id' => $this->cash->id, 'material' => 'cash', 'weight' => $weight, 'rate' => 1,
                'net_total' => $value, 'grand_total' => $value]],
        ]);
        foreach ([[1, 100000, 'exactly its amount'], [0, 100000, 'no quantity'], [-500, 500, 'negative quantity']] as [$weight, $value, $why]) {
            try {
                $send($weight, $value);
                $this->fail('Billed ₹' . $value . ' on a cash amount of ' . $weight);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        $this->assertEquals(600000, $this->cashStock());
        $this->assertSame(0, \App\Models\SalesInvoice::count());
    }

    /** A product the branch never held is refused too — it used to bill with no deduction at all. */
    public function test_an_item_the_branch_does_not_hold_cannot_be_billed(): void
    {
        $other = Branch::create(['name' => 'Empty Shop', 'country' => 'IN', 'is_active' => true]);
        try {
            app(SalesService::class)->generateInvoice([
                'branch_id' => $other->id, 'plan_id' => $this->plan->id,
                'customer' => ['name' => 'Buyer Y', 'phone' => '9000000917', 'pan' => 'ABCDE1234F'],
                'cart' => [['catalog_product_id' => $this->cash->id, 'material' => 'cash', 'weight' => 1000, 'rate' => 1, 'net_total' => 1000, 'grand_total' => 1000]],
            ]);
            $this->fail('A branch with no cash stock billed cash.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('available ₹0', $e->getMessage());
        }
        $this->assertSame(0, \App\Models\SalesInvoice::count());
    }
}
