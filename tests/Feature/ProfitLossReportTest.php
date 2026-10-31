<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\ProfitLossReport;
use App\Models\User;
use App\Support\Accounts\LegacyProfitLossSource;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Profit & Loss Report (Lord board 2026-10-21): scheme incoming, payout classes, stock maintenance, professional statement — from the Lord DB. */
class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Hand-built figures in the shape figures() returns, so the statement arithmetic is checked without the Lord DB. */
    protected function figures(): array
    {
        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-06-30');

        return [
            'from' => $from, 'to' => $to, 'branch' => null, 'fy' => '2026-27',
            'scheme' => collect([
                // G12 = 100% holding (board): the book records bvalue = investment, the P&L charges no stock allocation.
                (object) ['planid' => '202', 'scheme' => 'G12 Forward contractors', 'contracts' => 10, 'invested' => 1000000.0, 'installments' => 0.0, 'installment_count' => 0,
                    'book_allocation' => 1000000.0, 'allocation' => 0.0, 'holding' => 1000000.0, 'is_holding' => true, 'treatment' => 'Holding 100% — gold due at 12-month settlement (board)', 'plan_allocation_pct' => 100.0,
                    'cbc_provision' => 150000.0, 'ic_provision' => 50000.0, 'returns' => 0.0,
                    'active_contracts' => 8, 'active_invested' => 800000.0, 'active_allocation' => 0.0,
                    'closed_contracts' => 2, 'closed_invested' => 200000.0, 'closed_allocation' => 0.0],
                (object) ['planid' => '200', 'scheme' => 'G11 Gold Savings Plan PLUS3', 'contracts' => 5, 'invested' => 10000.0, 'installments' => 40000.0, 'installment_count' => 20,
                    'book_allocation' => 10000.0, 'allocation' => 10000.0, 'holding' => 0.0, 'is_holding' => false, 'treatment' => 'Gold stock 100% / holding 0%', 'plan_allocation_pct' => 100.0,
                    'cbc_provision' => 0.0, 'ic_provision' => 0.0, 'returns' => 0.0,
                    'active_contracts' => 5, 'active_invested' => 10000.0, 'active_allocation' => 10000.0,
                    'closed_contracts' => 0, 'closed_invested' => 0.0, 'closed_allocation' => 0.0],
            ]),
            'rules' => collect([
                '202' => (object) ['planid' => '202', 'name' => 'G12 Forward contractors', 'product' => 'CASH', 'min_value' => 10000.0, 'allocation_pct' => 100.0, 'cbc_pct' => 15.0, 'cbc_months' => 12, 'cbc_total_pct' => 180.0,
                    'ic' => [7.5, 2.25, 0.75, 0.75, 0.75, 0.6, 0.6, 0.6, 0.6, 0.6], 'ic_total_pct' => 15.0, 'level' => [2.5, 1.0, 0.5, 0.5, 0.5], 'level_total_pct' => 5.0, 'level_months' => 12,
                    'billing_margin_pct' => 2.0, 'gm_margin_pct' => 100.0, 'status' => 'ACTIVE', 'is_holding' => true],
                '200' => (object) ['planid' => '200', 'name' => 'G11 Gold Savings Plan PLUS3', 'product' => 'CASH', 'min_value' => 1000.0, 'allocation_pct' => 100.0, 'cbc_pct' => 0.0, 'cbc_months' => 0, 'cbc_total_pct' => 0.0,
                    'ic' => [10.0, 3.0, 2.0, 1.5, 0.75, 0.75, 0.5, 0.5, 0.5, 0.5], 'ic_total_pct' => 20.0, 'level' => [], 'level_total_pct' => 0.0, 'level_months' => 11,
                    'billing_margin_pct' => 10.0, 'gm_margin_pct' => 0.0, 'status' => 'ACTIVE', 'is_holding' => false],
            ]),
            'payouts' => [
                'ic' => ['paid' => 30000.0, 'pending' => 2000.0, 'count' => 40],
                'cbc' => ['paid' => 90000.0, 'pending' => 500000.0, 'count' => 60],
                'gap' => ['paid' => 8000.0, 'pending' => 0.0, 'gross' => 10000.0, 'tds' => 2000.0, 'count' => 3],   // 20% TDS off the 10,000 gross
                'billing' => ['paid' => 4000.0, 'pending' => 0.0, 'count' => 8],
                'digi' => ['paid' => 70000.0, 'pending' => 1000.0, 'grams' => 5.0, 'count' => 9],
                'qr_issued' => ['count' => 12, 'grams' => 8.0, 'value' => 120000.0],
                'qr_redeemed' => ['count' => 4, 'grams' => 2.0, 'value' => 30000.0],
            ],
            'sales' => collect([
                (object) ['metal' => 'gold', 'kind' => 'bullion', 'item' => '1 g Gold', 'pieces' => 10.0, 'grams' => 10.0, 'metal_value' => 140000.0, 'charges' => 7000.0, 'taxable' => 147000.0],
                (object) ['metal' => 'silver', 'kind' => 'bullion', 'item' => '10 g Silver', 'pieces' => 2.0, 'grams' => 20.0, 'metal_value' => 5000.0, 'charges' => 1000.0, 'taxable' => 6000.0],
                (object) ['metal' => 'cash', 'kind' => 'cash', 'item' => 'CASH', 'pieces' => 1010000.0, 'grams' => 0.0, 'metal_value' => 1010000.0, 'charges' => 0.0, 'taxable' => 1010000.0],
            ]),
            'invoices' => ['count' => 17, 'taxable' => 1163000.0, 'cgst' => 2295.0, 'sgst' => 2295.0, 'grand' => 1167590.0, 'received' => 1167590.0],
            'stock' => collect([
                (object) ['id' => 1, 'name' => 'LORD JEWELLER', 'is_hq' => true, 'gold_pcs' => 100.0, 'gold_g' => 100.0, 'gold_cost' => 1400000.0, 'silver_pcs' => 10.0, 'silver_g' => 100.0, 'silver_cost' => 25000.0, 'cash' => 500000.0],
                (object) ['id' => 35, 'name' => 'TRICHY AISHWARYAM', 'is_hq' => false, 'gold_pcs' => 10.0, 'gold_g' => 10.0, 'gold_cost' => 140000.0, 'silver_pcs' => 0.0, 'silver_g' => 0.0, 'silver_cost' => 0.0, 'cash' => 20000.0],
            ]),
            'cash' => 520000.0,
            'purchases' => collect(),
            'purchaseMetal' => ['gold' => 0.0, 'silver' => 0.0, 'gold_g' => 0.0, 'silver_g' => 0.0],
            'monthly' => collect([
                (object) ['month' => '2026-04', 'contracts' => 10, 'invested' => 1000000.0, 'installments' => 20000.0, 'sales_metal' => 145000.0, 'sales_profit' => 8000.0, 'scheme_sales' => 1010000.0, 'ic' => 32000.0, 'cbc' => 90000.0, 'gap_gross' => 10000.0, 'gap_net' => 9000.0, 'billing' => 4000.0],
                (object) ['month' => '2026-06', 'contracts' => 5, 'invested' => 10000.0, 'installments' => 20000.0],
            ]),
            'pending_qrs' => ['count' => 8, 'grams' => 5.5, 'value' => 80000.0],
            'rates' => ['gold' => 14000.0, 'silver' => 250.0],
            'inputs' => ['rent' => 30000.0, 'salaries_extra' => 50000.0, 'income_tax' => 1000.0],
            'source_notes' => [],
        ];
    }

    protected function cell(array $section, string $label, int $col = 1): string
    {
        foreach ($section['rows'] as $row) {
            if ($row[0] === $label) {
                return $row[$col];
            }
        }
        $this->fail("Line '{$label}' not on the sheet");
    }

    public function test_hq_can_open_it_but_dealers_and_support_cannot(): void
    {
        $this->assertTrue(ProfitLossReport::canAccess());

        $dealer = User::factory()->create(['status' => 'active']);
        $dealer->assignRole('distributor');
        $this->actingAs($dealer);
        $this->assertFalse(ProfitLossReport::canAccess());

        $support = User::factory()->create(['status' => 'active']);
        $support->assignRole('support');
        $this->actingAs($support);
        $this->assertFalse(ProfitLossReport::canAccess());
    }

    public function test_statement_arithmetic(): void
    {
        $l = (new ProfitLossReport)->statementLines($this->figures());

        $this->assertSame(145000.0, $l['sales_metal']);
        $this->assertSame(8000.0, $l['sales_profit']);
        $this->assertSame(1010000.0, $l['invested']);
        $this->assertSame(40000.0, $l['installments']);
        $this->assertSame(1203000.0, $l['total_income']);                        // 145,000 + 8,000 + 1,010,000 + 40,000
        $this->assertSame(10000.0, $l['allocation']);                            // G12 is holding, only G11 gold counts
        $this->assertSame(1000000.0, $l['forward_holding']);
        $this->assertSame(1048000.0, $l['gross_margin']);                         // 1,203,000 − 145,000 − 10,000
        $this->assertSame(32000.0, $l['ic']);
        $this->assertSame(90000.0, $l['cbc']);
        $this->assertSame(10000.0, $l['gap_gross']);
        $this->assertSame(4000.0, $l['billing']);
        $this->assertSame(136000.0, $l['total_distributions']);
        $this->assertSame(912000.0, $l['after_distributions']);
        $this->assertSame(80000.0, $l['total_expenses']);                         // rent 30,000 + salaries 50,000
        $this->assertSame(2000.0, $l['gap_tds']);                                // 20% TDS, a government payable, not added back
        $this->assertSame(832000.0, $l['pbt']);                                   // 912,000 − 80,000
        $this->assertSame(831000.0, $l['net_result']);                            // − tax 1,000
        $this->assertSame(4590.0, $l['gst']);
    }

    public function test_sections_lay_out_the_four_board_items_and_the_trend(): void
    {
        $page = new ProfitLossReport;
        $s = collect($page->sectionsFor($this->figures(), 'full'))->keyBy('heading');

        $scheme = $s['1. Scheme-wise incoming amount — bond allocation / investment (01 Apr 2026 – 30 Jun 2026)'];
        $this->assertSame('15', $scheme['kv']['Contracts started']);
        $this->assertSame('10,10,000.00', $scheme['kv']['Investment received']);
        $this->assertSame('40,000.00', $scheme['kv']['Gold Saving installments received']);
        $this->assertSame('G12 Forward contractors', $scheme['rows'][0][1]);
        $this->assertSame('0.00', $scheme['rows'][0][7]);                         // no stock allocation on G12
        $this->assertSame('0.00 %', $scheme['rows'][0][8]);
        $this->assertSame('10,00,000.00', $scheme['rows'][0][9]);                 // holding
        $this->assertSame('8,00,000.00', $scheme['rows'][0][12]);                 // 1,000,000 − 0 − 150,000 − 50,000
        $this->assertStringStartsWith('Holding 100%', $scheme['rows'][0][13]);
        $this->assertSame('TOTAL', $scheme['rows'][2][1]);
        $this->assertSame('10,50,000.00', $scheme['rows'][2][6]);                 // total incoming
        $this->assertSame('10,00,000.00', $scheme['kv']['Held by Lord (holding)']);

        $rules = $s['1b. Scheme rules — legacy plan master (tbl_planjewel)'];
        $this->assertSame([1, 'G12 Forward contractors', '10,000.00', 'Holding 100% (gold at 12-month settlement)', '15.00 % × 12 = 180.00 %', '15.00 % (10 levels)', '5.00 % × 12 months', '2.00 %', '100.00 %', '257.00 %'], $rules['rows'][0]);
        $this->assertSame('100.00 %', $rules['rows'][1][3]);

        $this->assertSame('13', $scheme['kv']['Active contracts']);
        $this->assertSame('2', $scheme['kv']['Closed (inactive) contracts']);

        $status = $s['1a. Contract status per scheme — active vs closed (inactive) contracts started 01 Apr 2026 – 30 Jun 2026'];
        $this->assertSame([1, 'G12 Forward contractors', '10', '8', '8,00,000.00', '0.00', '2', '2,00,000.00', '0.00', '80.00 %', '0.00'], $status['rows'][0]);
        $this->assertSame('TOTAL', $status['rows'][2][1]);
        $this->assertSame('13', $status['rows'][2][3]);
        $this->assertSame('86.67 %', $status['rows'][2][9]);
        $this->assertSame('13 · 8,10,000.00', $status['kv']['Active']);

        $pay = $s['2. Payout distribution classification (01 Apr 2026 – 30 Jun 2026)'];
        $this->assertSame('8,000.00', $this->cell($pay, 'Sales profit (retained by Lord)', 4));
        $this->assertSame('30,000.00', $this->cell($pay, 'Sales Incentive (IC)', 2));
        $this->assertSame('2,000.00', $this->cell($pay, 'Sales Incentive (IC)', 3));
        $this->assertSame('90,000.00', $this->cell($pay, 'Promotional Incentive (CBC)', 2));
        $this->assertSame('10,000.00', $this->cell($pay, 'GAP / Level income', 4));          // gross
        $this->assertSame('2,000.00', $this->cell($pay, 'GAP / Level income', 5));           // 20% TDS
        $this->assertSame('8,000.00', $this->cell($pay, 'GAP / Level income', 6));           // net handed over
        $this->assertSame('4,000.00', $this->cell($pay, 'Billing Margin (dealers)', 4));
        $this->assertSame('1,36,000.00', $this->cell($pay, 'TOTAL member / dealer distributions', 4));
        $this->assertSame('1,34,000.00', $this->cell($pay, 'TOTAL member / dealer distributions', 6));

        $metal = $s['2a. Sales profit by metal — making + wastage charges on jewellery sales'];
        $this->assertSame(['Gold', '10', '10.000 g', '1,40,000.00', '7,000.00', '1,47,000.00', '5.00 %'], $metal['rows'][0]);

        // LORD JEWELLER (#1) is never part of the stock portion (board 2026-10-21).
        $stock = $s["3. Lord's stock maintenance — dealer network as the book stands on ".now()->format('d M Y')];
        $this->assertArrayNotHasKey('Gold at HQ', $stock['kv']);
        $this->assertSame('10.000 g · 1,40,000.00', $stock['kv']['Gold in dealer network']);
        $this->assertSame('181.82 %', $stock['kv']['Physical gold vs QR obligation']);       // 10 g vs 5.5 g
        $this->assertStringContainsString('100.000 g gold held there', $stock['kv']['Excluded']);
        $this->assertCount(1, $stock['rows']);
        $this->assertSame('Dealer', $stock['rows'][0][0]);
        $this->assertSame('20,000.00', $stock['rows'][0][8]);

        // Digi-gold withdrawals / QR redemptions are not payout classes any more.
        $classes = array_column($pay['rows'], 0);
        $this->assertNotContains('Digi-gold withdrawals', $classes);
        $this->assertNotContains('Gold QR redemptions', $classes);

        $st = $s['4. Professional statement — Profit & Loss 01 Apr 2026 – 30 Jun 2026'];
        $this->assertSame('12,03,000.00', $this->cell($st, 'Total income'));
        $this->assertSame('-10,000.00', $this->cell($st, 'Gold-stock allocated to scheme customers'));
        $this->assertSame('10,00,000.00', $this->cell($st, 'Held on G12 forward contracts (gold due at 12-month settlement)'));
        $this->assertSame('-1,36,000.00', $this->cell($st, 'Total distributions'));
        $this->assertSame('-80,000.00', $this->cell($st, 'Total operating expenses'));
        $this->assertSame('8,31,000.00', $this->cell($st, 'NET RESULT'));
        $this->assertSame('2,000.00', $this->cell($st, 'TDS 20% withheld on GAP (payable to government)'));
        $this->assertNotContains('Add: 10% deduction retained on GAP payouts', array_column($st['rows'], 0));
        $this->assertSame('1,20,000.00', $this->cell($st, 'Gold QRs allotted on bills'));
        $this->assertNotContains('Gold QRs redeemed', array_column($st['rows'], 0));
        $this->assertSame('2,000.00', $pay['kv']['TDS 20% on GAP (payable to government)']);

        $c = $s['Conclusion — NET PROFIT of 8,31,000.00 (01 Apr 2026 – 30 Jun 2026)'];
        $this->assertSame('NET PROFIT 8,31,000.00', $c['kv']['Result']);
        $this->assertSame('69.08 %', $c['kv']['On total income']);
        $this->assertStringContainsString('covers', $c['kv']['Why']);
        $this->assertSame(['NET PROFIT', '8,31,000.00', '69.08 %'], end($c['rows']));
        $this->assertNotContains('Add: 10% deduction retained on GAP payouts', array_column($c['rows'], 0));
        // The conclusion closes the report: last section before the notes, in full and statement modes.
        $full = collect($page->sectionsFor($this->figures(), 'full'))->pluck('heading');
        $this->assertSame('Conclusion — NET PROFIT of 8,31,000.00 (01 Apr 2026 – 30 Jun 2026)', $full[$full->count() - 2]);
        $this->assertSame('Notes & basis of preparation', $full->last());

        $trend = $s['Month-by-month trend (01 Apr 2026 – 30 Jun 2026)'];
        $this->assertCount(4, $trend['rows']);                                   // Apr, May, Jun + TOTAL
        $this->assertSame('Apr 2026', $trend['rows'][0][0]);
        $this->assertSame('8,92,000.00', $trend['rows'][0][10]);                 // 1,000,000 + 20,000 + 8,000 − 32,000 − 90,000 − 10,000 − 4,000
        $this->assertSame('May 2026', $trend['rows'][1][0]);
        $this->assertSame('0.00', $trend['rows'][1][2]);
        $this->assertSame('TOTAL', $trend['rows'][3][0]);
        $this->assertSame('15', $trend['rows'][3][1]);

        $this->assertArrayHasKey('Notes & basis of preparation', $s);

        $only = collect($page->sectionsFor($this->figures(), 'statement'));
        $this->assertCount(3, $only);                                            // statement + conclusion + notes
        $this->assertStringStartsWith('Conclusion — NET ', $only[1]['heading']);
    }

    public function test_reads_the_lord_book_when_it_is_reachable(): void
    {
        if (! LegacyProfitLossSource::available()) {
            $this->markTestSkipped('Legacy lordicl database not reachable on this machine.');
        }

        $s = collect(Livewire::test(ProfitLossReport::class)
            ->fillForm(['statement' => 'full', 'from' => '2026-04-01', 'to' => '2026-09-30'])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');

        $scheme = $s['1. Scheme-wise incoming amount — bond allocation / investment (01 Apr 2026 – 30 Sep 2026)'];
        $this->assertGreaterThan(0, (int) str_replace(',', '', $scheme['kv']['Contracts started']));
        $this->assertSame('TOTAL', end($scheme['rows'])[1]);

        $pay = $s['2. Payout distribution classification (01 Apr 2026 – 30 Sep 2026)'];
        $this->assertNotSame('0.00', $this->cell($pay, 'Sales Incentive (IC)', 4));
        $this->assertNotSame('0.00', $this->cell($pay, 'Promotional Incentive (CBC)', 4));

        $stock = $s["3. Lord's stock maintenance — dealer network as the book stands on ".now()->format('d M Y')];
        $this->assertSame('Dealer', $stock['rows'][0][0]);
        $this->assertStringStartsWith('Conclusion — NET ', collect($s)->keys()->get(collect($s)->count() - 2));

        $this->assertArrayHasKey('4. Professional statement — Profit & Loss 01 Apr 2026 – 30 Sep 2026', $s);

        Livewire::test(ProfitLossReport::class)
            ->fillForm(['statement' => 'statement', 'from' => '2026-04-01', 'to' => '2026-09-30'])
            ->call('exportXlsx')->assertFileDownloaded();
    }
}
