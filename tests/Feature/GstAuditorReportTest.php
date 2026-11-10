<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\GstReport;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\Member;
use App\Models\Plan;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Gst\GstAuditorReport;
use App\Support\Gst\GstAuditorWorkbook;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Auditor GST registers (Nov 2026): B2C = consumer redemptions under the filing GSTIN,
 * B2B = dealer supplies; running INV NO continues the auditor's sequence, REF NO = the
 * original document number; GSTR-1 workbook derives from the same rows.
 */
class GstAuditorReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hq;

    private Branch $dealer;

    private Branch $selfDealer;

    private Plan $plan;

    private Plan $rdPlan;

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
        $this->plan = Plan::firstOrCreate(['code' => 'P206'], ['name' => ['en' => 'G10 Gold Purchase Plan'], 'plan_type' => 1, 'type' => 'digital',
            'min_value' => 1000, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_active' => true, 'validity_months' => 12]);
        $this->rdPlan = Plan::firstOrCreate(['code' => 'P208'], ['name' => ['en' => 'G11 Gold Savings Plan PLUS2'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 1000, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_active' => true, 'validity_months' => 11]);
    }

    private function sale(string $no, string $date, Branch $branch, Plan $plan, float $taxable, float $qty = 1, float $rate = 1): SalesInvoice
    {
        $m = Member::create(['member_code' => 'M'.$no, 'name' => 'Buyer '.$no, 'phone' => '98'.str_pad((string) crc32($no), 8, '0'),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'address' => "2/112, West St\nAttur", 'city' => 'Attur',
            'rank_id' => \App\Models\Rank::query()->orderBy('id')->value('id'),
            'pan' => 'FUPPS2885R', 'aadhaar' => '457296910990']);
        $inv = SalesInvoice::create(['invoice_no' => $no, 'date' => $date, 'branch_id' => $branch->id, 'customer_member_id' => $m->id,
            'customer_name' => $m->name, 'cross_total' => $taxable, 'net_total' => $taxable, 'cgst' => $taxable * 0.015, 'sgst' => $taxable * 0.015,
            'grand_total' => $taxable * 1.03, 'fx_rate' => 1]);
        SaleLine::create(['invoice_id' => $inv->id, 'material' => 'gold', 'description' => 'Gold', 'qty' => $qty, 'rate' => $rate,
            'making' => 20, 'wastage' => 20, 'line_total' => $taxable]);
        Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => $date,
            'value' => $taxable, 'epin_value' => $taxable * 1.03, 'invoice_no' => $no, 'status' => 'active']);

        return $inv;
    }

    private function order(string $no, string $date, Branch $buyer, string $status = 'approved', string $source = 'order_form'): BranchOrderRequest
    {
        $r = BranchOrderRequest::create(['request_no' => $no, 'branch_id' => $buyer->id, 'current_branch_id' => $this->hq->id, 'no_of_items' => 2,
            'cross_total' => 20000, 'gst_total' => 600, 'grand_total' => 20600, 'payment_type' => 'cash', 'status' => $status, 'source' => $source]);
        BranchOrderRequest::whereKey($r->id)->update(['approved_at' => $date.' 11:00:00', 'created_at' => $date.' 10:00:00']);
        foreach ([['gold', 2, 7000, 2.5, 2.5], ['cash', 1, 5000, 0, 0]] as [$mat, $w, $rate, $mc, $wc]) {
            DB::table('branch_order_lines')->insert(['order_request_id' => $r->id, 'material' => $mat, 'description' => $mat, 'weight' => $w,
                'rate' => $rate, 'unit_price' => $rate, 'making_charge_pct' => $mc, 'wastage_charge_pct' => $wc, 'hallmark_charge' => 0,
                'gst_pct' => 3, 'line_total' => $w * $rate * (1 + ($mc + $wc) / 100)]);
        }

        return $r->fresh();
    }

    public function test_b2c_register_lists_consumer_redemptions_under_the_filing_gstin_with_legacy_arithmetic(): void
    {
        $this->sale('28224', '2026-11-03', $this->hq, $this->plan, 2011.8, 1, 1437);        // 1 g at 1437 + 40 % charges
        $this->sale('28225', '2026-11-05', $this->hq, $this->rdPlan, 5000);                 // Gold Saving plan → excluded
        $this->sale('28226', '2026-11-06', $this->dealer, $this->plan, 9000);               // other GSTIN → excluded
        $this->sale('28227', '2026-11-02', $this->hq, $this->plan, 100000, 100000, 1);      // value-denominated bill

        $rows = GstAuditorReport::number((new GstAuditorReport('new', '2026-11-01', '2026-11-30'))->b2cRows(), 2217);

        $this->assertCount(2, $rows);
        $this->assertSame([2217, 2218], array_column($rows, 'INV NO'));
        $this->assertSame(['28227', '28224'], array_column($rows, 'REF NO'), 'date order, original invoice no as REF NO');
        $r = $rows[1];
        $this->assertSame('02 Nov 2026', $rows[0]['INV DATE']->format('d M Y'));
        $this->assertSame('M28224', $r['CUSID']);
        $this->assertSame('2/112, West St Attur', $r['ADDRESS'], 'line breaks collapsed like the auditor sheet');
        $this->assertSame(['BTOC', 'NA', 'BIS 916 HUID GOLD', '71181000', 1], [$r['BILL MODE'], $r['GST NUMBER'], $r['ITEM DESCRIPTION'], $r['HSN CODE'], $r['QTY']]);
        $this->assertSame(1437.0, $r['NET VALUE']);
        $this->assertSame(574.8, $r['CHARGES  (MC(2.5%)+WC(2.5%))']);
        $this->assertSame(2011.8, $r['TAXABLE AMOUNT']);
        $this->assertSame(60.35, $r['GST (3%)']);
        $this->assertSame(30.18, $r['SGST']);
        $this->assertSame(2072.15, $r['BILLING VALUE']);
        $this->assertSame(['rows' => 2, 'billing' => 105072.15, 'taxable' => 102011.8, 'gst' => 3060.35], GstAuditorReport::totals($rows));
    }

    public function test_b2b_register_consolidates_approved_dealer_orders_and_skips_self_gstin_and_cash(): void
    {
        $this->order('ORD-1', '2026-11-04', $this->dealer);
        $this->order('ORD-2', '2026-11-06', $this->dealer, 'approved', 'redemption');
        $this->order('ORD-3', '2026-11-07', $this->dealer, 'pending');
        $this->order('ORD-4', '2026-11-08', $this->selfDealer);
        $this->order('ORD-5', '2026-10-30', $this->dealer);

        $report = new GstAuditorReport('new', '2026-11-01', '2026-11-30');
        $rows = GstAuditorReport::number($report->b2bRows(), 5271);

        $this->assertSame(['ORD-1', 'ORD-2'], array_column($rows, 'REF NO'));
        $this->assertSame([5271, 5272], array_column($rows, 'INV NO'));
        $this->assertSame(['BO', 'RO'], array_column($rows, 'BILL MODE'), 'redemption restock = RO, stock request = BO');
        $r = $rows[0];
        $this->assertSame('33AAYFT2079A1Z1', $r['GST NUMBER']);
        $this->assertSame('No 103 Ponnagar', $r['ADDRESS']);
        $this->assertSame(1, $r['QTY'], 'cash line is not goods');
        $this->assertSame(2.0, $r['GOLD WEIGHT(GM)']);
        $this->assertSame(14000.0, $r['NET VALUE']);
        $this->assertSame(700.0, $r['CHARGES  (MC(2.5%)+WC(2.5%))']);
        $this->assertSame(14700.0, $r['TAXABLE AMOUNT']);
        $this->assertSame(441.0, $r['GST (3%)']);
        $this->assertSame(220.5, $r['CGST']);
        $this->assertSame(20600.0, $r['BILLING VALUE']);
        $this->assertCount(1, $report->notes);
        $this->assertStringContainsString('1 approved order(s) were left out', $report->notes[0]);
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
        $this->sale('28224', '2026-11-03', $this->hq, $this->plan, 2011.8, 1, 1437);
        $this->order('ORD-1', '2026-11-04', $this->dealer);
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
        $this->assertSame(GstAuditorReport::B2C_COLUMNS, $c['Sheet1'][0]);
        $this->assertSame(2217, $c['Sheet1'][1][0]);
        $this->assertInstanceOf(\DateTimeInterface::class, $c['Sheet1'][1][1]);
        $this->assertEquals(2072.15, $c['Sheet1'][1][15]);
        $b = $read($dir.'/b2b.xlsx');
        $this->assertSame(GstAuditorReport::B2B_COLUMNS, $b['Sheet1'][0]);
        $this->assertSame('ORD-1', $b['Sheet1'][1][2]);
        $g = $read($dir.'/gstr1.xlsx');
        $this->assertSame(['B2B', 'B2CS', 'HSN'], array_keys($g));
        $this->assertSame(['33AAYFT2079A1Z1', 'Trichy Aishwaryam', '5271'], array_slice($g['B2B'][1], 0, 3));
        $this->assertEquals(14700, $g['B2B'][1][11]);
        $this->assertEquals(2011.8, $g['B2CS'][1][4]);
        $this->assertSame('71181000', $g['HSN'][1][0]);
        $this->assertEquals(3, $g['HSN'][1][3], 'grams: 1 g consumer + 2 g dealer');
        $this->assertEquals(round(2011.8 + 14700, 2), $g['HSN'][1][5]);
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_page_runs_shows_both_registers_and_exports_the_three_workbooks(): void
    {
        $this->sale('28224', '2026-11-03', $this->hq, $this->plan, 2011.8, 1, 1437);
        $this->order('ORD-1', '2026-11-04', $this->dealer);

        $page = Livewire::test(GstReport::class)
            ->fillForm(['from' => '2026-11-01', 'to' => '2026-11-30', 'source' => 'new', 'b2c_start' => 2217, 'b2b_start' => 5271])
            ->call('run')->assertHasNoFormErrors();
        $sections = $page->get('sections');
        $this->assertStringContainsString('B2C', $sections[0]['heading']);
        $this->assertSame('2217 – 2217', $sections[0]['kv']['INV NO range']);
        $this->assertStringContainsString('B2B', $sections[1]['heading']);
        $this->assertSame('5271 – 5271', $sections[1]['kv']['INV NO range']);

        $page->call('exportB2c')->assertFileDownloaded('GSTR REPORT B2C Nov 2026.xlsx');
        $page->call('exportB2b')->assertFileDownloaded('GSTR REPORT B2B Nov 2026.xlsx');
        $page->call('exportGstr1')->assertFileDownloaded('GSTR1 Nov 2026.xlsx');
        $this->assertSame(2218, GstAuditorReport::nextNumber('b2c', '2026-12-01', '2026-12-31'), 'export records the range used');
        $this->assertSame(5272, GstAuditorReport::nextNumber('b2b', '2026-12-01', '2026-12-31'));
    }
}
