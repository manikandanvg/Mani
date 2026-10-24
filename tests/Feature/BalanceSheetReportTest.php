<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\BalanceSheetReport;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\MemberWallet;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Accounts\LegacyAccountsSource;
use App\Support\Accounts\ManualLedger;
use App\Support\Accounts\MetalUnits;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Balance Sheet Report (2026-10-14): the auditor's workbook from either book + manual audit inputs. */
class BalanceSheetReportTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected CatalogProduct $gold;

    protected CatalogProduct $silver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->hq = Branch::firstOrCreate(['level' => 'hq'], ['name' => 'Head Office', 'country' => 'IN', 'is_active' => true]);
        $this->gold = CatalogProduct::create(['code' => 'T-G1', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'default_purity' => '22K', 'is_active' => true]);
        $this->silver = CatalogProduct::create(['code' => 'T-S10', 'name' => ['en' => '10 g Silver'], 'material' => 'silver', 'default_purity' => '22K', 'is_active' => true]);

        // Stock on the book now: 10 × 1 g gold @ ₹7,000 a piece, 5 × 10 g silver @ ₹900 a piece.
        Stock::create(['branch_id' => $this->hq->id, 'catalog_product_id' => $this->gold->id, 'quantity' => 10, 'purity' => '22K', 'last_rate' => 7000]);
        Stock::create(['branch_id' => $this->hq->id, 'catalog_product_id' => $this->silver->id, 'quantity' => 5, 'purity' => '22K', 'last_rate' => 900]);

        // One sale inside the period: 2 × 1 g gold at ₹7,500/g, line total ₹16,500 (₹1,500 of charges), 3% GST split.
        $inv = SalesInvoice::create(['invoice_no' => 'TEST-1', 'date' => '2026-06-10', 'customer_name' => 'Walk-in', 'branch_id' => $this->hq->id,
            'cross_total' => 16500, 'discount' => 0, 'net_total' => 16500, 'cgst' => 247.5, 'sgst' => 247.5, 'tax_total' => 495, 'grand_total' => 16995,
            'received' => 16995, 'payment_type' => 'cash', 'gold_rate' => 7500, 'silver_rate' => 90]);
        SaleLine::create(['invoice_id' => $inv->id, 'catalog_product_id' => $this->gold->id, 'material' => 'gold', 'description' => '1 g Gold',
            'qty' => 2, 'rate' => 7500, 'making' => 0, 'wastage' => 0, 'line_total' => 16500]);

        // One purchase inside the period (paid by cheque): 5 g gold at ₹7,000/g, GST ₹1,050.
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Madurai Supplier', 'is_active' => true]);
        $p = Purchase::create(['ref_no' => 'PO-1', 'purchase_date' => '2026-05-20', 'vendor_id' => $vendor->id, 'branch_id' => $this->hq->id,
            'gold_rate' => 7000, 'silver_rate' => 90, 'gross_total' => 35000, 'gst_total' => 1050, 'grand_total' => 36050, 'payment_type' => 'cheque']);
        PurchaseLine::create(['purchase_id' => $p->id, 'catalog_product_id' => $this->gold->id, 'material' => 'gold', 'weight' => 5, 'purity' => '22K',
            'rate' => 7000, 'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'line_total' => 36050]);

        MemberWallet::create(['member_id' => 1, 'cash_balance' => 1200, 'coupon_balance' => 0, 'epin_balance' => 0, 'currency_code' => 'INR']);
    }

    protected function runReport(array $form = []): \Illuminate\Support\Collection
    {
        return collect(Livewire::test(BalanceSheetReport::class)
            ->fillForm(array_merge(['statement' => 'workbook', 'source' => 'next', 'basis' => 'cost', 'from' => '2026-04-01', 'to' => '2026-06-30'], $form))
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');
    }

    /** rows are [label, amount, label, amount] — find a label on either side. */
    protected function cell(array $section, string $label): string
    {
        foreach ($section['rows'] as $row) {
            if ($row[0] === $label) {
                return $row[1];
            }
            if ($row[2] === $label) {
                return $row[3];
            }
        }
        $this->fail("Line '{$label}' not on the sheet");
    }

    public function test_hq_can_open_it_but_dealers_and_support_cannot(): void
    {
        $this->assertTrue(BalanceSheetReport::canAccess());

        $dealer = User::factory()->create(['status' => 'active']);
        $dealer->assignRole('distributor');
        $this->actingAs($dealer);
        $this->assertFalse(BalanceSheetReport::canAccess());

        $support = User::factory()->create(['status' => 'active']);
        $support->assignRole('support');
        $this->actingAs($support);
        $this->assertFalse(BalanceSheetReport::canAccess());
    }

    public function test_units_and_financial_year_helpers(): void
    {
        $this->assertSame(0.3, MetalUnits::gramsFromName('300 mg Gold'));
        $this->assertSame(8.0, MetalUnits::gramsFromName('8 g Gold'));
        $this->assertSame(1000.0, MetalUnits::gramsFromName('1000 g Silver'));
        $this->assertNull(MetalUnits::gramsFromName('Bridal Necklace'));
        $this->assertSame('bullion', MetalUnits::kind('500 mg Gold'));
        $this->assertSame('bullion', MetalUnits::kind('Gold Coin'));
        $this->assertSame('jewellery', MetalUnits::kind('Bridal Necklace'));
        $this->assertSame('2026-27', MetalUnits::fy(\Illuminate\Support\Carbon::parse('2026-10-15')));
        $this->assertSame('2025-26', MetalUnits::fy(\Illuminate\Support\Carbon::parse('2026-03-31')));
    }

    public function test_workbook_renders_every_sheet_from_the_new_system(): void
    {
        $s = $this->runReport();

        $bs = $s['Balance Sheet as on 30 Jun 2026'];
        $this->assertSame(['Particulars', 'Amount (₹)', 'Particulars', 'Amount (₹)'], $bs['columns']);
        // Stock now: gold 10 × 7,000 = 70,000; silver 5 × 900 = 4,500 — both weight-named coins = bullion.
        $this->assertSame('70,000.00', $this->cell($bs, 'Gold Bullion / Bars'));
        $this->assertSame('4,500.00', $this->cell($bs, 'Silver Bullion'));
        $this->assertSame('0.00', $this->cell($bs, 'Gold Jewellery Stock'));
        $this->assertSame('0.00', $this->cell($bs, 'Sundry Creditors'));           // the new system keeps no credit purchases
        $this->assertSame('1,200.00', $this->cell($bs, 'Other Payables'));         // member cash wallet
        $this->assertSame('0.00', $this->cell($bs, 'GST Payable'));                // 495 output < 1,050 input
        $this->assertSame('555.00', $this->cell($bs, 'GST Input Credit'));
        $this->assertSame('0.00', $this->cell($bs, 'Customer Receivables'));
        $this->assertSame('0.00', $this->cell($bs, 'Opening Capital'));             // nothing keyed in yet

        $pl = $s['Profit & Loss 01 Apr 2026 – 30 Jun 2026'];
        $this->assertSame('0.00', $this->cell($pl, 'Gold Jewellery Sales'));
        $this->assertSame('15,000.00', $this->cell($pl, 'Bullion Sales'));          // 2 g × 7,500
        $this->assertSame('1,500.00', $this->cell($pl, 'Making Charges Income'));
        $this->assertSame('16,500.00', $this->cell($pl, 'Total Revenue'));
        $this->assertSame('35,000.00', $this->cell($pl, 'Purchases – Gold'));
        // Closing stock at cost (now) = 74,500; opening = 74,500 − 35,000 purchases + 15,000 sold = 54,500.
        $this->assertSame('-74,500.00', $this->cell($pl, 'Closing Stock (gold + silver)'));
        $this->assertSame('54,500.00', $this->cell($pl, 'Opening Stock (gold + silver)'));
        $this->assertSame('15,000.00', $this->cell($pl, 'Gross Cost of Sales'));
        $this->assertSame('1,500.00', $this->cell($pl, 'Gross Profit'));
        $this->assertSame('1,500.00', $this->cell($pl, 'Net Profit'));
        $this->assertSame('1,500.00', $this->cell($bs, 'Add: Net Profit'));
        $this->assertSame('1,500.00', $this->cell($bs, 'Net Capital'));

        $stock = $s['Jewellery Stock Summary (as the book stands on '.now()->format('d M Y').')'];
        $this->assertSame('Gold Bullion', $stock['rows'][1][0]);
        $this->assertSame('10.000 g', $stock['rows'][1][3]);
        $this->assertSame('70,000.00', $stock['rows'][1][7]);
        $this->assertSame('TOTAL STOCK', $stock['rows'][8][0]);
        $this->assertSame('74,500.00', $stock['rows'][8][7]);

        $register = $s['Gold / Silver Inventory Register'];
        $this->assertCount(2, $register['rows']);
        $this->assertSame('1 g Gold', $register['rows'][0][2]);
        $this->assertSame($this->hq->fresh()->name, $register['rows'][0][13]);   // HQ = LORD JEWELLER, Sivakasi (2026-10-24)

        $gold = $s['Gold Metal Account / Weight Ledger'];
        $this->assertSame('Opening balance', $gold['rows'][0][1]);
        $this->assertSame('7.000 g', $gold['rows'][0][2]);                           // 10 now − 5 bought + 2 sold
        $this->assertSame('Purchases', $gold['rows'][1][1]);
        $this->assertSame('5.000 g', $gold['rows'][1][3]);
        $this->assertSame('Sales', $gold['rows'][2][1]);
        $this->assertSame('2.000 g', $gold['rows'][2][4]);
        $this->assertSame('10.000 g', $gold['rows'][3][5]);                          // closing
        $this->assertArrayHasKey('Silver Metal Account / Weight Ledger', $s);

        $recv = $s['Customer / Dealer Receivables as on 30 Jun 2026'];
        $this->assertSame('0', $recv['kv']['Open invoices']);
        $this->assertSame('1', $recv['kv']['Invoices in the period']);

        $pay = $s['Supplier / Goldsmith Payables as on 30 Jun 2026'];
        $this->assertCount(1, $pay['rows']);
        $this->assertSame('Madurai Supplier', $pay['rows'][0][1]);
        $this->assertSame('Paid', $pay['rows'][0][8]);
        $this->assertSame('36,050.00', $pay['kv']['Purchased (incl. GST)']);
        $this->assertSame('0.00', $pay['kv']['Sundry creditors']);

        $this->assertArrayHasKey('Notes & basis of preparation', $s);
    }

    public function test_market_basis_values_closing_stock_at_the_live_rate(): void
    {
        // Seeder live rate: gold 7,000 /g, silver 90 /g → gold 10 g = 70,000, silver 50 g = 4,500.
        \App\Models\LiveRate::where('country', 'IN')->update(['gold' => 8000, 'silver' => 100]);
        $bs = $this->runReport(['statement' => 'balance_sheet', 'basis' => 'market'])['Balance Sheet as on 30 Jun 2026'];

        $this->assertSame('80,000.00', $this->cell($bs, 'Gold Bullion / Bars'));
        $this->assertSame('5,000.00', $this->cell($bs, 'Silver Bullion'));
        $this->assertSame('Market value (live rate)', $bs['kv']['Closing stock valued at']);
    }

    public function test_single_statement_shows_only_that_sheet_plus_notes(): void
    {
        $s = $this->runReport(['statement' => 'payables']);

        $this->assertCount(2, $s);
        $this->assertArrayHasKey('Supplier / Goldsmith Payables as on 30 Jun 2026', $s);
        $this->assertArrayHasKey('Notes & basis of preparation', $s);
    }

    public function test_audit_inputs_are_saved_per_financial_year_and_flow_into_the_sheets(): void
    {
        $page = Livewire::test(BalanceSheetReport::class)
            ->fillForm(['statement' => 'workbook', 'source' => 'next', 'basis' => 'cost', 'from' => '2026-04-01', 'to' => '2026-06-30',
                'inputs' => array_merge(array_fill_keys(array_keys(ManualLedger::fields()), 0),
                    ['opening_capital' => 500000, 'bank_balance' => 250000, 'rent' => 30000, 'land_building' => 1000000, 'accumulated_depreciation' => 100000])])
            ->call('saveInputs')->assertHasNoFormErrors();

        $this->assertSame('500000', Setting::where('group', 'accounts')->where('key', '2026-27.opening_capital')->value('value'));
        $this->assertSame(['opening_capital' => 500000.0, 'rent' => 30000.0], array_intersect_key(ManualLedger::load('2026-27'), ['opening_capital' => 1, 'rent' => 1]));
        $this->assertSame(0.0, ManualLedger::load('2025-26')['opening_capital']);   // another year stays untouched

        $s = collect($page->call('run')->get('sections'))->keyBy('heading');
        $bs = $s['Balance Sheet as on 30 Jun 2026'];
        $pl = $s['Profit & Loss 01 Apr 2026 – 30 Jun 2026'];
        $this->assertSame('5,00,000.00', $this->cell($bs, 'Opening Capital'));
        $this->assertSame('2,50,000.00', $this->cell($bs, 'Bank Balance'));
        $this->assertSame('9,00,000.00', $this->cell($bs, 'Net Fixed Assets'));     // 10,00,000 − 1,00,000
        $this->assertSame('30,000.00', $this->cell($pl, 'Rent & Maintenance'));
        $this->assertSame('-28,500.00', $this->cell($pl, 'Net Profit'));           // 1,500 gross profit − 30,000 rent
        $this->assertSame('4,71,500.00', $this->cell($bs, 'Net Capital'));          // 5,00,000 − 28,500

        // A fresh mount of the page loads the saved inputs for the current FY.
        $fresh = Livewire::test(BalanceSheetReport::class);
        $this->assertSame(500000.0, (float) data_get($fresh->get('data'), 'inputs.opening_capital'));
    }

    public function test_excel_and_pdf_exports_download_the_workbook(): void
    {
        $page = Livewire::test(BalanceSheetReport::class)
            ->fillForm(['statement' => 'workbook', 'source' => 'next', 'basis' => 'cost', 'from' => '2026-04-01', 'to' => '2026-06-30']);

        $page->call('exportXlsx')->assertFileDownloaded();
        $page->call('exportCsv')->assertFileDownloaded();
        $page->call('exportPdf')->assertFileDownloaded();
    }

    public function test_legacy_source_reads_the_lord_book_when_it_is_reachable(): void
    {
        if (! LegacyAccountsSource::available()) {
            $this->markTestSkipped('Legacy lordicl database not reachable on this machine.');
        }

        $s = $this->runReport(['source' => 'legacy', 'statement' => 'balance_sheet', 'from' => '2026-04-01', 'to' => '2026-09-30']);
        $bs = $s['Balance Sheet as on 30 Sep 2026'];
        $this->assertSame('Lord DB (lordicl)', $bs['kv']['Source']);
        $this->assertNotSame('0.00', $this->cell($bs, 'Gold Bullion / Bars'));
        $this->assertNotSame('0.00', $this->cell($bs, 'Gold Saving Scheme / Customer Collections'));
        $this->assertNotSame('0.00', $this->cell($bs, 'Cash in Hand'));
    }
}
