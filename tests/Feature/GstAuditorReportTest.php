<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\GstReport;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Rank;
use App\Models\RedemptionInvoice;
use App\Models\RedemptionLine;
use App\Models\User;
use App\Support\Gst\GstAuditorReport;
use App\Support\Gst\GstAuditorWorkbook;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Auditor GST registers (board 2026-11-11): redeemed invoices only. B2C = redemptions at
 * branches under the filing GSTIN, B2B = redemptions at dealer branches billed to the
 * dealer; running INV NO continues the auditor's sequence, REF NO = the redemption
 * invoice no, BILLING VALUE = TAXABLE + GST exactly; GSTR-1 derives from the same rows.
 */
class GstAuditorReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hq;

    private Branch $dealer;

    private Branch $selfDealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->hq = Branch::query()->orderBy('id')->firstOrFail();
        $this->hq->update(['gst_no' => '33AREPB3962B3ZD']);
        $this->dealer = Branch::create(['name' => 'Trichy Aishwaryam', 'country' => 'IN', 'is_active' => true, 'gst_no' => '33AAYFT2079A1Z1',
            'address' => "No 103\nPonnagar", 'city' => 'Trichy', 'phone' => '7092345777', 'kind' => 'dealer', 'level' => 'retailer']);
        $this->selfDealer = Branch::create(['name' => 'LORD JEWELLER (merged)', 'country' => 'IN', 'is_active' => true, 'gst_no' => '33AREPB3962B3ZD',
            'kind' => 'dealer', 'level' => 'retailer']);
    }

    /**
     * A native redemption invoice. $lines = [[label, pieces, grams per piece, price per piece, making+wastage %], …]
     * e.g. [['1 g Gold', 1, 1.0, 13390, 5], ['100 mg Gold', 6, 0.1, 1339, 40]] → taxable 25,307.10 like legacy estid 4463.
     */
    private function redeem(string $no, string $date, Branch $branch, array $lines): RedemptionInvoice
    {
        $m = Member::create(['member_code' => 'M'.$no, 'name' => 'Buyer '.$no, 'phone' => '98'.str_pad((string) crc32($no), 8, '0'),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'address' => "2/112, West St\nAttur", 'city' => 'Attur',
            'rank_id' => Rank::query()->orderBy('id')->value('id'), 'pan' => 'FUPPS2885R', 'aadhaar' => '457296910990']);
        $taxable = 0.0;
        $built = [];
        foreach ($lines as [$label, $pieces, $grams, $price, $pct]) {
            $amount = round($pieces * $price, 2);
            $charge = round($amount * $pct / 200, 2);
            $taxable += $amount + 2 * $charge;
            $built[] = ['description' => $label, 'material' => str_contains($label, 'Silver') ? 'silver' : 'gold', 'unit_weight' => $grams,
                'quantity' => $pieces, 'rate' => $price, 'making' => $charge, 'wastage' => $charge, 'gst_pct' => 3, 'amount' => $amount,
                'line_total' => round(($amount + 2 * $charge) * 1.03, 2)];
        }
        $half = round($taxable * 0.015, 2);
        $inv = RedemptionInvoice::create(['invoice_no' => $no, 'invoice_date' => $date, 'member_id' => $m->id, 'branch_id' => $branch->id,
            'reference_no' => '28224', 'referrer_name' => $m->name, 'payment_mode' => 'wallet', 'gold_rate' => 12730, 'silver_rate' => 200,
            'taxable_total' => round($taxable, 2), 'cgst' => $half, 'sgst' => $half, 'tax_total' => 2 * $half, 'tax_regime' => 'gst',
            'grand_total' => round($taxable + 2 * $half, 2), 'currency_code' => 'INR', 'fx_rate' => 1, 'dealer_created' => $branch->id !== $this->hq->id]);
        foreach ($built as $l) {
            RedemptionLine::create($l + ['redemption_invoice_id' => $inv->id]);
        }

        return $inv;
    }

    /**
     * User 2026-11-13: a redemption is billed GST-INSIDE — the line value (gold + making + wastage)
     * is the bill. The register takes the GST out of net value and charges, and still ties
     * BILLING VALUE = TAXABLE + GST = the invoice grand total.
     */
    public function test_register_takes_the_gst_out_of_a_gst_inside_redemption(): void
    {
        $inv = $this->redeem('RDM-HQ-0001', '2026-11-13', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5]]);   // value 14,059.50
        $inv->update(['taxable_total' => 13650, 'cgst' => 204.75, 'sgst' => 204.75, 'tax_total' => 409.5, 'grand_total' => 14059.5]);
        $inv->lines()->update(['line_total' => 14059.5]);

        $r = (new GstAuditorReport('new', '2026-11-01', '2026-11-30'))->b2cRows()[0];

        $this->assertSame(14059.5, $r['BILLING VALUE']);
        $this->assertSame(13650.0, $r['TAXABLE AMOUNT']);
        $this->assertSame(409.5, $r['GST (3%)']);
        $this->assertSame(13000.0, $r['NET VALUE'], '13,390 a piece with its 3 % taken out');
        $this->assertSame(650.0, $r['CHARGES  (MC(2.5%)+WC(2.5%))']);
    }

    public function test_b2c_register_lists_redemptions_under_the_filing_gstin_one_row_per_invoice(): void
    {
        $this->redeem('4463', '2026-11-03', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5], ['100 mg Gold', 6, 0.1, 1339, 40]]);
        $this->redeem('4464', '2026-11-02', $this->hq, [['5 g Silver', 1, 5.0, 1100, 10]]);
        $this->redeem('4465', '2026-11-05', $this->dealer, [['2 g Gold', 1, 2.0, 27360, 5]]);            // dealer GSTIN → B2B
        $this->redeem('4466', '2026-11-06', $this->selfDealer, [['1 g Gold', 1, 1.0, 13390, 5]]);        // merged LJ record → B2C
        $this->redeem('4467', '2026-10-31', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5]]);                // out of period

        $report = new GstAuditorReport('new', '2026-11-01', '2026-11-30');
        $rows = GstAuditorReport::number($report->b2cRows(), 2217);

        $this->assertSame(['4464', '4463', '4466'], array_column($rows, 'REF NO'), 'date order, redemption invoice no as REF NO');
        $this->assertSame([2217, 2218, 2219], array_column($rows, 'INV NO'));
        $r = $rows[1];
        $this->assertSame('02 Nov 2026', $rows[0]['INV DATE']->format('d M Y'));
        $this->assertSame('M4463', $r['CUSID']);
        $this->assertSame('2/112, West St Attur', $r['ADDRESS'], 'line breaks collapsed like the auditor sheet');
        $this->assertSame(['BTOC', 'NA', 'BIS 916 HUID GOLD', '71181000', 7], [$r['BILL MODE'], $r['GST NUMBER'], $r['ITEM DESCRIPTION'], $r['HSN CODE'], $r['QTY']]);
        $this->assertSame(1.6, $r['GOLD WEIGHT(GM)'], '1 g + 6 × 100 mg');
        $this->assertSame(12730.0, $r['GOLD RATE/G']);
        $this->assertSame(21424.0, $r['NET VALUE']);
        $this->assertSame(3883.1, $r['CHARGES  (MC(2.5%)+WC(2.5%))']);
        $this->assertSame(25307.1, $r['TAXABLE AMOUNT']);
        $this->assertSame(759.22, $r['GST (3%)']);
        $this->assertSame(379.61, $r['SGST']);
        $this->assertSame(379.61, $r['CGST']);
        $this->assertSame(26066.32, $r['BILLING VALUE'], 'taxable + GST exactly');
        $silver = $rows[0];
        $this->assertSame(['SILVER 999', '71189000', 0.0, 1210.0, 1246.3], [$silver['ITEM DESCRIPTION'], $silver['HSN CODE'], $silver['GOLD WEIGHT(GM)'], $silver['TAXABLE AMOUNT'], $silver['BILLING VALUE']]);
        $this->assertSame(['rows' => 3, 'billing' => 41793.9, 'taxable' => 40576.6, 'gst' => 1217.3], GstAuditorReport::totals($rows));
        $this->assertSame([], $report->notes);
    }

    public function test_b2b_register_lists_redemptions_at_dealer_branches_billed_to_the_dealer(): void
    {
        $this->redeem('4470', '2026-11-04', $this->dealer, [['2 g Gold', 1, 2.0, 27360, 5]]);
        $this->redeem('4471', '2026-11-06', $this->dealer, [['1 g Silver', 2, 1.0, 120, 20]]);
        $this->redeem('4472', '2026-11-07', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5]]);                // own GSTIN → B2C
        $this->redeem('4473', '2026-10-30', $this->dealer, [['1 g Gold', 1, 1.0, 13390, 5]]);            // out of period
        $this->redeem('4474', '2026-11-08', $this->selfDealer, [['1 g Gold', 1, 1.0, 13390, 5]]);        // carries the filing GSTIN → B2C

        $report = new GstAuditorReport('new', '2026-11-01', '2026-11-30');
        $rows = GstAuditorReport::number($report->b2bRows(), 5271);

        $this->assertSame(['4470', '4471'], array_column($rows, 'REF NO'));
        $this->assertSame([5271, 5272], array_column($rows, 'INV NO'));
        $this->assertSame(['RO', 'RO'], array_column($rows, 'BILL MODE'));
        $r = $rows[0];
        $this->assertSame('33AAYFT2079A1Z1', $r['GST NUMBER']);
        $this->assertSame('Trichy Aishwaryam', $r['CUSNAME']);
        $this->assertSame('No 103 Ponnagar', $r['ADDRESS']);
        $this->assertSame(['BIS 916 HUID GOLD', '71181000', 1, 2.0, 12730.0], [$r['ITEM DESCRIPTION'], $r['HSN CODE'], $r['QTY'], $r['GOLD WEIGHT(GM)'], $r['ITEM RATE/G']]);
        $this->assertSame(27360.0, $r['NET VALUE']);
        $this->assertSame(1368.0, $r['CHARGES  (MC(2.5%)+WC(2.5%))']);
        $this->assertSame(28728.0, $r['TAXABLE AMOUNT']);
        $this->assertSame(861.84, $r['GST (3%)']);
        $this->assertSame(430.92, $r['CGST']);
        $this->assertSame(29589.84, $r['BILLING VALUE'], 'taxable + GST exactly');
        $this->assertSame(['SILVER 999', '71189000', 2, 0.0, 288.0, 296.64], [$rows[1]['ITEM DESCRIPTION'], $rows[1]['HSN CODE'], $rows[1]['QTY'], $rows[1]['GOLD WEIGHT(GM)'], $rows[1]['TAXABLE AMOUNT'], $rows[1]['BILLING VALUE']]);
        $this->assertCount(2, $report->b2cRows(), 'HQ + merged LORD JEWELLER record are B2C');
    }

    public function test_numbering_continues_from_the_last_export_and_reuses_a_months_own_start(): void
    {
        $this->assertSame(2217, GstAuditorReport::nextNumber('b2c', '2026-10-01', '2026-10-31'));
        $this->assertSame(5271, GstAuditorReport::nextNumber('b2b', '2026-10-01', '2026-10-31'));

        GstAuditorReport::recordRun('b2c', '2026-10-01', '2026-10-31', 2217, 872);
        $this->assertSame(3089, GstAuditorReport::nextNumber('b2c', '2026-11-01', '2026-11-30'));
        $this->assertSame(2217, GstAuditorReport::nextNumber('b2c', '2026-10-01', '2026-10-31'), 're-export of the same month keeps its start');

        GstAuditorReport::recordRun('b2c', '2026-10-01', '2026-10-31', 2217, 880);   // re-export with more rows
        $this->assertSame(3097, GstAuditorReport::nextNumber('b2c', '2026-11-01', '2026-11-30'));
        $this->assertCount(1, GstAuditorReport::runs('b2c'));
    }

    public function test_workbooks_carry_the_auditor_layout_and_gstr1_derives_from_the_registers(): void
    {
        $this->redeem('4463', '2026-11-03', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5], ['100 mg Gold', 6, 0.1, 1339, 40]]);
        $this->redeem('4470', '2026-11-04', $this->dealer, [['2 g Gold', 1, 2.0, 27360, 5], ['5 g Silver', 1, 5.0, 1100, 10]]);
        $report = new GstAuditorReport('new', '2026-11-01', '2026-11-30');
        $b2c = GstAuditorReport::number($report->b2cRows(), 2217);
        $b2b = GstAuditorReport::number($report->b2bRows(), 5271);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gst-test-'.uniqid();
        mkdir($dir);
        GstAuditorWorkbook::register('b2c', $b2c, $dir.'/b2c.xlsx');
        GstAuditorWorkbook::register('b2b', $b2b, $dir.'/b2b.xlsx');
        GstAuditorWorkbook::gstr1($b2c, $b2b, $dir.'/gstr1.xlsx');

        $read = function (string $file): array {
            $reader = new Reader;
            $reader->open($file);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = $row->toArray();
                }
            }
            $reader->close();

            return $sheets;
        };
        $c = $read($dir.'/b2c.xlsx');
        $this->assertSame(GstAuditorReport::B2C_COLUMNS, $c['Sheet1'][0], 'hidden HSN split never reaches the sheet');
        $this->assertCount(count(GstAuditorReport::B2C_COLUMNS), $c['Sheet1'][1]);
        $this->assertSame(2217, $c['Sheet1'][1][0]);
        $this->assertInstanceOf(\DateTimeInterface::class, $c['Sheet1'][1][1]);
        $this->assertEquals(26066.32, $c['Sheet1'][1][15]);
        $b = $read($dir.'/b2b.xlsx');
        $this->assertSame(GstAuditorReport::B2B_COLUMNS, $b['Sheet1'][0]);
        $this->assertSame('4470', $b['Sheet1'][1][2]);
        $this->assertSame('BIS 916 HUID GOLD + SILVER 999 5 g', $b['Sheet1'][1][10]);
        $g = $read($dir.'/gstr1.xlsx');
        $this->assertSame(['B2B', 'B2CS', 'HSN'], array_keys($g));
        $this->assertSame(['33AAYFT2079A1Z1', 'Trichy Aishwaryam', '5271'], array_slice($g['B2B'][1], 0, 3));
        $this->assertEquals(29938, $g['B2B'][1][11], 'dealer invoice taxable: 28,728 gold + 1,210 silver');
        $this->assertEquals(25307.1, $g['B2CS'][1][4]);
        $this->assertSame('71181000', $g['HSN'][1][0]);
        $this->assertEquals(3.6, $g['HSN'][1][3], 'gold grams: 1.6 consumer + 2 dealer');
        $this->assertEquals(round(25307.1 + 28728, 2), $g['HSN'][1][5]);
        $this->assertSame('71189000', $g['HSN'][2][0]);
        $this->assertEquals(5, $g['HSN'][2][3]);
        $this->assertEquals(1210, $g['HSN'][2][5]);
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_page_runs_shows_both_registers_and_exports_the_three_workbooks(): void
    {
        $this->redeem('4463', '2026-11-03', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5]]);
        $this->redeem('4470', '2026-11-04', $this->dealer, [['2 g Gold', 1, 2.0, 27360, 5]]);

        $page = Livewire::test(GstReport::class)
            ->fillForm(['from' => '2026-11-01', 'to' => '2026-11-30', 'source' => 'new', 'b2c_start' => 2217, 'b2b_start' => 5271])
            ->call('run')->assertHasNoFormErrors();
        $sections = $page->get('sections');
        $this->assertStringContainsString('B2C', $sections[0]['heading']);
        $this->assertSame('2217 – 2217', $sections[0]['kv']['INV NO range']);
        $this->assertCount(count(GstAuditorReport::B2C_COLUMNS), $sections[0]['rows'][0]);
        $this->assertStringContainsString('B2B', $sections[1]['heading']);
        $this->assertSame('5271 – 5271', $sections[1]['kv']['INV NO range']);

        $page->call('exportB2c')->assertFileDownloaded('GSTR REPORT B2C Nov 2026.xlsx');
        $page->call('exportB2b')->assertFileDownloaded('GSTR REPORT B2B Nov 2026.xlsx');
        $page->call('exportGstr1')->assertFileDownloaded('GSTR1 Nov 2026.xlsx');
        $this->assertSame(2218, GstAuditorReport::nextNumber('b2c', '2026-12-01', '2026-12-31'), 'export records the range used');
        $this->assertSame(5272, GstAuditorReport::nextNumber('b2b', '2026-12-01', '2026-12-31'));
    }

    public function test_distributor_login_gets_its_own_sales_and_purchase_registers_without_the_auditor_sequence(): void
    {
        $this->redeem('4470', '2026-11-04', $this->dealer, [['2 g Gold', 1, 2.0, 27360, 5]]);
        $this->redeem('4471', '2026-11-06', $this->dealer, [['1 g Silver', 2, 1.0, 120, 20]]);
        $this->redeem('4463', '2026-11-03', $this->hq, [['1 g Gold', 1, 1.0, 13390, 5]]);                // HQ's own, not the dealer's
        $other = Branch::create(['name' => 'Other Dealer', 'country' => 'IN', 'is_active' => true, 'gst_no' => '33AAAAA0000A1Z5', 'kind' => 'dealer', 'level' => 'retailer']);
        $this->redeem('4480', '2026-11-05', $other, [['1 g Gold', 1, 1.0, 13390, 5]]);                   // another dealer

        $user = User::factory()->create(['status' => 'active', 'branch_id' => $this->dealer->id]);
        $user->assignRole('distributor');
        $this->actingAs($user);

        $report = new GstAuditorReport('new', '2026-11-01', '2026-11-30', $this->dealer->id);
        $sales = $report->dealerSalesRows();
        $this->assertSame(['4470', '4471'], array_column($sales, 'INV NO'), 'the dealer\'s own redemption invoice numbers');
        $this->assertSame(['28224', '28224'], array_column($sales, 'REF NO'), 'the sale invoice the QR came from');
        $this->assertSame(['BTOC', 'NA', 'M4470', 28728.0, 29589.84], [$sales[0]['BILL MODE'], $sales[0]['GST NUMBER'], $sales[0]['CUSID'], $sales[0]['TAXABLE AMOUNT'], $sales[0]['BILLING VALUE']]);
        $purchases = $report->dealerPurchaseRows();
        $this->assertSame(GstAuditorReport::PURCHASE_COLUMNS, array_keys(array_diff_key($purchases[0], [GstAuditorReport::HSN_KEY => 1])));
        $this->assertSame([$this->hq->name, '33AREPB3962B3ZD', '4470', '28224', 28728.0, 861.84, 29589.84],
            [$purchases[0]['SELLER'], $purchases[0]['GSTN'], $purchases[0]['PO NO'], $purchases[0]['REF NO'], $purchases[0]['TAXABLE AMOUNT'], $purchases[0]['GST (3%)'], $purchases[0]['BILLING VALUE']]);
        $this->assertSame([], (new GstAuditorReport('new', '2026-11-01', '2026-11-30'))->dealerSalesRows(), 'needs a branch');

        $page = Livewire::test(GstReport::class)
            ->fillForm(['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->call('run')->assertHasNoFormErrors();
        $sections = $page->get('sections');
        $this->assertStringContainsString('Sales to customers', $sections[0]['heading']);
        $this->assertSame('2', $sections[0]['kv']['Rows']);
        $this->assertStringContainsString('Purchases from', $sections[1]['heading']);
        $this->assertSame(GstAuditorReport::PURCHASE_COLUMNS, $sections[1]['columns']);
        $this->assertSame(['exportSales', 'exportPurchases'], array_column($page->instance()->gstExportButtons(), 0));

        $page->call('exportSales')->assertFileDownloaded('GSTR SALES B2C Nov 2026.xlsx');
        $page->call('exportPurchases')->assertFileDownloaded('GSTR PURCHASE Nov 2026.xlsx');
        $this->assertSame(2217, GstAuditorReport::nextNumber('b2c', '2026-12-01', '2026-12-31'), 'a dealer export never touches the auditor sequence');
        $page->call('exportB2c')->assertForbidden();
    }
}
