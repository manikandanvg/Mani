<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\BusinessModelReport;
use App\Models\Plan;
use App\Models\User;
use App\Support\BusinessModel\LegacyLordSource;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lord DB source (board 2026-09-16 follow-up): the report reads the LIVE legacy
 * `lordicl` database. These checks only run where that database is reachable (dev
 * box / live server); elsewhere they are skipped, never failed.
 */
class BusinessModelReportLegacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! LegacyLordSource::available()) {
            $this->markTestSkipped('Legacy lordicl database not reachable on this machine.');
        }
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Plan::create(['code' => 'P203', 'hid' => 4, 'name' => ['en' => 'Taluka Dealership'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 500000, 'allocation_bv' => 20, 'allocation_cont' => 80, 'is_contract' => true, 'is_active' => true,
            'validity_months' => 24, 'settlement_cycle_months' => 24, 'settlement' => '24 month after bv renewal with opening stock']);
        Plan::create(['code' => 'P209', 'hid' => null, 'name' => ['en' => 'G11 Gold Savings Plan PLUS1'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 1500, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_redeem' => true, 'is_contract' => true, 'is_active' => true,
            'validity_months' => 11, 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 1, 'settlement_close' => true,
            'rd_qr_grams' => 0.1, 'rd_qr_on' => 'always']);
        Plan::create(['code' => 'P214', 'hid' => 1, 'name' => ['en' => 'Regional Dealership'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 50000000, 'allocation_bv' => 20, 'allocation_cont' => 80, 'is_contract' => true, 'is_active' => true,
            'validity_months' => 24, 'settlement_cycle_months' => 24]);
    }

    public function test_legacy_source_maps_plans_by_name_and_reads_bonds(): void
    {
        $src = new LegacyLordSource;
        $this->assertSame('203', $src->legacyPlanId(Plan::where('code', 'P203')->first()));
        $this->assertSame('210', $src->legacyPlanId(Plan::where('code', 'P209')->first()), 'PLUS1 is planid 210 in the Lord DB');
        $this->assertNull($src->legacyPlanId(Plan::where('code', 'P214')->first()), 'Regional Dealership never existed in the Lord DB');

        $plus1 = Plan::where('code', 'P209')->first();
        $from = Carbon::parse('2000-01-01');
        $to = Carbon::parse('2099-12-31');
        $contracts = $src->contracts($plus1, $from, $to);
        $this->assertGreaterThan(0, $contracts->count());

        $first = $contracts->first();
        $this->assertSame('active', in_array($first->status, ['active', 'closed'], true) ? 'active' : $first->status);
        $this->assertTrue($first->is_rd);
        $this->assertNotEmpty($first->invoice_no);

        // QR rows come back keyed by bond id with installment-sized gold worth.
        $qrs = $src->qrs($contracts->take(200)->pluck('bond_id')->all(), $to);
        $this->assertGreaterThan(0, $qrs->flatten(1)->count());
        $this->assertTrue($qrs->flatten(1)->every(fn ($q) => $q->gram_worth > 0 && $q->created_at instanceof Carbon));

        // No cash-settlement register in the Lord DB.
        $this->assertCount(0, $src->settlements($contracts->pluck('id')->all(), $to));
    }

    public function test_page_runs_on_the_lord_db_and_flags_coverage(): void
    {
        $sections = collect(Livewire::test(BusinessModelReport::class)
            ->fillForm(['source' => 'legacy', 'from' => '2025-01-01', 'to' => now()->toDateString()])
            ->call('run')
            ->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');

        $overview = $sections->first(fn ($s, $h) => str_starts_with($h, 'Lord structure'));
        $this->assertSame('Lord DB (lordicl · tbl_bond)', $overview['kv']['Data source']);
        $this->assertArrayHasKey('Settlement note', $overview['kv']);

        $regional = $sections['1.1 Regional Dealership — scheme (P214)'];
        $this->assertSame('Not offered in the Lord DB — no bonds to list.', $regional['kv']['Data note']);

        $taluka = $sections['1.4 Taluka Dealership — active contracts (bonds)'];
        $this->assertGreaterThan(0, (int) $taluka['kv']['Contracts']);
        $this->assertStringStartsWith('Bond ', $taluka['rows'][0][0]);
        $this->assertContains($taluka['rows'][0][5], [$taluka['rows'][0][5]]);   // Term column present
        $this->assertMatchesRegularExpression('/^(Concluded|Runs to) /', $taluka['rows'][0][5]);

        $lite = collect(Livewire::test(BusinessModelReport::class)
            ->fillForm(['mode' => 'lite', 'source' => 'legacy', 'from' => '2024-01-01', 'to' => now()->toDateString()])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');
        $this->assertArrayHasKey('Lord Jeweller - Business Model Report', $lite->all());
        $liteTaluk = $lite['Dealers (Offline-Dealers) — 3. Taluk'];
        $this->assertSame('80%', $liteTaluk['kv']['Gold Stock']);
        $this->assertGreaterThan(0, (int) $liteTaluk['kv']['Distributors joined in period']);
        $litePlus1 = $lite['Customer (Gold-Saving) — 1. G11 plus 1'];
        $this->assertGreaterThan(0, (int) $litePlus1['kv']['Closed contracts']);
        $this->assertMatchesRegularExpression('/\(LJW/', $litePlus1['rows'][0][0]);

        $dealers = collect(Livewire::test(BusinessModelReport::class)
            ->fillForm(['mode' => 'dealers', 'source' => 'legacy'])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');
        $dealerTaluk = $dealers['Dealers (Offline-Dealers) — 3. Taluk'];
        $this->assertGreaterThan(50, (int) $dealerTaluk['kv']['Dealers Count'], 'ci_users desiid 3 = Taluk logins');
        $this->assertArrayNotHasKey('Data source', $dealers['Lord Jeweller - Dealers Report']['kv']);
        $this->assertMatchesRegularExpression('/\(LJW/', $dealerTaluk['rows'][0][0]);
        $this->assertGreaterThan(0, (int) $dealers['Customer (Gold-Saving) — 1. G11 plus 1']['kv']['Closed contracts']);

        $plus1 = $sections['3.1 G11 Gold Savings Plan PLUS1 — installments & 100 mg gold allocations'];
        $this->assertGreaterThan(0, (int) $plus1['kv']['Installments received (as on To date)']);
        $this->assertArrayHasKey('Settlement due · settled (issued / handed over)', $plus1['kv']);
        $this->assertStringStartsWith('0 /', $plus1['kv']['Settlement due · pending'], 'Lord DB: every past-due settlement counts as handed over');
    }
}
