<?php

namespace Tests\Feature;

use App\Filament\Support\BranchBankForm;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Expense;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Stock;
use App\Models\SupportTicket;
use App\Services\SalesReturnService;
use App\Services\StockReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Board 2026-11-27: stock returns priced with making + wastage + GST and checked in pieces,
 * sales returns tied to the distributor's bill, the expense ledger with capital assets, the
 * ticket categories and the forgiving bank fields.
 */
class Board20261127Test extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected CatalogProduct $coin;

    protected function setUp(): void
    {
        parent::setUp();
        Branch::create(['id' => 1, 'name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $this->branch = Branch::create(['name' => 'Ret Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $this->coin = CatalogProduct::create([
            'code' => '13', 'name' => ['en' => '4 g Gold'], 'material' => 'gold', 'default_weight' => 4, 'gm_margin' => 0,
            'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'hallmark_charge' => 0, 'gst_pct' => 3,
            'hsn_code' => '71082000', 'is_active' => true,
        ]);
        LiveRate::create(['country' => 'IN', 'gold' => 10000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        Stock::create(['branch_id' => $this->branch->id, 'catalog_product_id' => $this->coin->id, 'quantity' => 5]);
    }

    public function test_stock_return_adds_making_wastage_and_gst_and_keeps_the_bill(): void
    {
        // 3 coins of 4 g = 12 g × 10,000 = 1,20,000; MC 3,000; WC 3,000; GST 3 % of 1,26,000 = 3,780
        $return = app(StockReturnService::class)->submit([
            'branch_id' => $this->branch->id,
            'bill_no' => ' ORD-000123 ',
            'attachments' => ['stock-return-bills/bill.pdf'],
            'notes' => 'Slow moving',
            'lines' => [['catalog_product_id' => $this->coin->id, 'quantity' => 3]],
        ]);

        $line = $return->lines->first();
        $this->assertEquals(3.0, (float) $line->quantity);
        $this->assertEquals(12.0, (float) $line->weight);
        $this->assertEquals(120000.0, (float) $line->metal_value);
        $this->assertEquals(3000.0, (float) $line->making);
        $this->assertEquals(3000.0, (float) $line->wastage);
        $this->assertEquals(3780.0, (float) $line->gst);
        $this->assertEquals(129780.0, (float) $return->total_amount);
        $this->assertSame('ORD-000123', $return->bill_no);
        $this->assertSame(['stock-return-bills/bill.pdf'], $return->attachments);

        app(StockReturnService::class)->approve($return);
        $this->assertEquals(2.0, (float) Stock::where('branch_id', $this->branch->id)->value('quantity'));
        $this->assertEquals(3.0, (float) Stock::where('branch_id', 1)->value('quantity'));
        $this->assertEquals(129780.0, (float) $this->branch->fresh()->digi_cash_balance);
    }

    public function test_stock_return_is_checked_in_pieces_across_lines(): void
    {
        $this->expectException(HttpException::class);
        // 3 + 3 pieces of the same coin against 5 held
        app(StockReturnService::class)->submit([
            'branch_id' => $this->branch->id,
            'lines' => [
                ['catalog_product_id' => $this->coin->id, 'quantity' => 3],
                ['catalog_product_id' => $this->coin->id, 'quantity' => 3],
            ],
        ]);
    }

    public function test_sales_return_bill_must_be_the_distributors_own_bill_at_this_branch(): void
    {
        $rank = \App\Models\Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true])->id;
        $member = Member::forceCreate(['member_code' => 'LJT01', 'name' => 'Buyer', 'phone' => '9000000001', 'branch_id' => $this->branch->id, 'joined_on' => '2026-01-01', 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        $other = Member::forceCreate(['member_code' => 'LJT02', 'name' => 'Other', 'phone' => '9000000002', 'branch_id' => $this->branch->id, 'joined_on' => '2026-01-01', 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        DB::table('sales_invoices')->insert([
            'invoice_no' => 'INV-RB-0001', 'date' => '2026-11-01', 'customer_member_id' => $member->id, 'customer_name' => 'Buyer',
            'branch_id' => $this->branch->id, 'grand_total' => 50000, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $svc = app(SalesReturnService::class);

        $this->assertTrue($svc->checkBill('INV-RB-0001', $member->id, $this->branch->id)['ok']);
        $this->assertFalse($svc->checkBill('INV-RB-0001', $other->id, $this->branch->id)['ok']);      // someone else's bill
        $this->assertFalse($svc->checkBill('INV-RB-0001', $member->id, 1)['ok']);                     // another branch
        $this->assertFalse($svc->checkBill('INV-NOPE', $member->id, $this->branch->id)['ok']);        // no such bill
        $this->assertFalse($svc->checkBill('', $member->id, $this->branch->id)['ok']);

        $return = $svc->create(['branch_id' => $this->branch->id, 'member_id' => $member->id, 'bill_no' => 'INV-RB-0001',
            'catalog_product_id' => $this->coin->id, 'quantity' => 1, 'require_bill' => true]);
        $this->assertSame('INV-RB-0001', $return->bill_no);

        $this->expectException(HttpException::class);
        $svc->create(['branch_id' => $this->branch->id, 'member_id' => $other->id, 'bill_no' => 'INV-RB-0001',
            'catalog_product_id' => $this->coin->id, 'quantity' => 1, 'require_bill' => true]);
    }

    public function test_expense_ledger_splits_expenses_and_capital_assets(): void
    {
        $car = Expense::create(['expense_date' => '2026-04-10', 'category' => 'asset_vehicles', 'title' => 'Cars', 'amount' => 6000000,
            'payment_mode' => 'bank', 'status' => Expense::APPROVED]);
        Expense::create(['expense_date' => '2026-04-30', 'category' => 'fuel', 'title' => 'Fuel', 'amount' => 90000,
            'payment_mode' => 'bank', 'status' => Expense::APPROVED]);
        Expense::create(['expense_date' => '2026-05-10', 'category' => 'asset_computers', 'title' => 'Not yet approved', 'amount' => 500000,
            'payment_mode' => 'bank', 'status' => Expense::SUBMITTED]);

        $this->assertSame(Expense::KIND_ASSET, $car->fresh()->kind);
        $this->assertSame(['vehicles' => 6000000.0], Expense::assetTotals(Carbon::parse('2026-12-31')));
        $this->assertSame([], Expense::assetTotals(Carbon::parse('2026-04-01')));

        // the Profit & Loss feed carries the fuel, never the car
        $rows = app(\App\Support\Accounts\NextAccountsSource::class)->expenses(Carbon::parse('2026-04-01'), Carbon::parse('2026-12-31'));
        $this->assertSame([90000.0], $rows->pluck('amount')->all());
    }

    public function test_expenses_calibrate_is_a_dry_run_by_default_and_never_doubles(): void
    {
        $args = ['--from' => '2026-10-15', '--to' => '2026-11-27', '--only' => 'rent,fuel,meeting,assets'];
        $this->artisan('expenses:calibrate', $args)->assertSuccessful();
        $this->assertSame(0, Expense::count());

        $this->artisan('expenses:calibrate', $args + ['--apply' => true])->assertSuccessful();
        // 4 assets + per month (Oct, Nov): rent, fuel, monthly meeting, weekly meetings
        $this->assertSame(12, Expense::count());
        $this->assertSame(0, Expense::where('status', Expense::APPROVED)->count());   // waits for the owner
        // Sunday is a holiday: no fuel that day, the weekly meeting is counted on the Saturday
        $this->assertEquals(15 * 3000, (float) Expense::where('source_key', 'fuel:2026-10')->value('amount'));   // 15–31 Oct less Sundays 18, 25
        $this->assertEquals(23 * 3000, (float) Expense::where('source_key', 'fuel:2026-11')->value('amount'));   // 1–27 Nov less 4 Sundays
        $this->assertEquals(3 * 25000, (float) Expense::where('source_key', 'meeting-weekly:2026-10')->value('amount'));   // Saturdays 17, 24, 31 Oct

        // a waiting row with an old figure is corrected, an approved one is left alone
        Expense::where('source_key', 'fuel:2026-10')->update(['amount' => 51000]);
        Expense::where('source_key', 'fuel:2026-11')->update(['amount' => 81000, 'status' => Expense::APPROVED]);
        $this->artisan('expenses:calibrate', $args + ['--apply' => true])->assertSuccessful();
        $this->assertEquals(45000.0, (float) Expense::where('source_key', 'fuel:2026-10')->value('amount'));
        $this->assertEquals(81000.0, (float) Expense::where('source_key', 'fuel:2026-11')->value('amount'));
        Expense::where('source_key', 'fuel:2026-11')->update(['amount' => 69000, 'status' => Expense::SUBMITTED]);
        $this->assertSame('2026-10-15', Expense::where('source_key', 'asset:asset_vehicles')->value('expense_date')->toDateString());

        $this->artisan('expenses:calibrate', $args + ['--apply' => true])->assertSuccessful();
        $this->assertSame(12, Expense::count());

        $this->artisan('expenses:calibrate', ['--undo' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame(0, Expense::count());
    }

    public function test_undo_approval_takes_the_row_out_of_the_auditor_reports(): void
    {
        $car = Expense::create(['expense_date' => '2026-04-10', 'category' => 'asset_vehicles', 'title' => 'Cars', 'amount' => 6000000,
            'payment_mode' => 'bank', 'status' => Expense::APPROVED, 'approved_at' => now()]);
        $this->assertSame(['vehicles' => 6000000.0], Expense::assetTotals(Carbon::parse('2026-12-31')));

        \App\Filament\Resources\ExpenseResource::undoApproval($car, 'Wrong amount');

        $car->refresh();
        $this->assertSame(Expense::SUBMITTED, $car->status);
        $this->assertNull($car->approved_at);
        $this->assertStringContainsString('Wrong amount', $car->reject_reason);
        $this->assertSame([], Expense::assetTotals(Carbon::parse('2026-12-31')));
        // a submitted row is no longer locked
        $this->assertFalse(\App\Filament\Resources\ExpenseResource::canUndoApproval($car));
    }

    public function test_ticket_has_the_cancel_and_settlement_categories(): void
    {
        $this->assertSame('Cancel Invoice/Contract', SupportTicket::CATEGORIES[SupportTicket::CATEGORY_CANCEL]);
        $this->assertSame('Settlements', SupportTicket::CATEGORIES['settlement']);
    }

    public function test_bank_fields_ignore_spaces_and_dashes(): void
    {
        $this->assertSame('123456789012', BranchBankForm::cleanAccount(' 1234 5678-9012 '));
        $this->assertSame('SBIN0001234', BranchBankForm::cleanIfsc(' sbin 0001234 '));
        $this->assertNull(BranchBankForm::cleanAccount('  '));
    }
}
