<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports\BusinessModelReport;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\ContractSettlement;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Business Model Report (board 2026-09-16): ladder + per-scheme active/closed blocks + P209 installments. */
class BusinessModelReportTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Plan $regional;

    protected Plan $retailer;

    protected Plan $plus1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->hq = Branch::firstOrCreate(['level' => 'hq'], ['name' => 'Head Office', 'country' => 'IN', 'is_active' => true]);
        Branch::create(['name' => 'Regional One', 'country' => 'IN', 'level' => 'regional', 'source_branch_id' => $this->hq->id, 'is_active' => true]);
        Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Distributor'], 'depth' => 0, 'is_active' => true]);

        $base = ['plan_type' => 2, 'type' => 'digital', 'is_contract' => true, 'is_active' => true, 'allocation_bv' => 20, 'allocation_cont' => 80,
            'validity_months' => 24, 'level_com_duration' => 24, 'settlement_cycle_months' => 24, 'settlement' => '24 month after contract renewal'];
        $this->regional = Plan::create($base + ['code' => 'P214', 'hid' => 1, 'name' => ['en' => 'Regional Dealership'], 'min_value' => 50000000]);
        Plan::create($base + ['code' => 'P207', 'hid' => 2, 'name' => ['en' => 'Zonal Dealership'], 'min_value' => 10000000]);
        $this->retailer = Plan::create($base + ['code' => 'P201', 'hid' => 5, 'name' => ['en' => 'G5 Retailer Plan'], 'min_value' => 100000,
            'max_sales_value' => 1500000, 'allocation_bv' => 50, 'allocation_cont' => 50, 'settlement_cycle_months' => 12, 'settlement_qr_pct' => 70]);
        $this->plus1 = Plan::create(['code' => 'P209', 'hid' => null, 'name' => ['en' => 'G11 Gold Savings Plan PLUS1'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 1500, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_redeem' => true, 'is_contract' => true, 'is_active' => true,
            'validity_months' => 11, 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 1, 'settlement_close' => true,
            'rd_qr_grams' => 0.1, 'rd_qr_on' => 'always', 'settlement' => '12 month 11m+ 1 month commission gold qr']);
    }

    protected function member(string $code): Member
    {
        return Member::create(['member_code' => $code, 'name' => 'Dist '.$code, 'phone' => '9'.random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('code', 'MEMBER')->value('id')]);
    }

    protected function contract(Plan $plan, string $code, float $amount, string $start, array $extra = []): MemberContract
    {
        $member = $this->member($code);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $this->hq->id, 'bond_date' => $start,
            'value' => $extra['bond_value'] ?? $amount, 'invoice_no' => 'INV-'.$code, 'status' => $extra['bond_status'] ?? 'active']);

        return MemberContract::create(['contract_no' => 'LJC-'.$code, 'bond_id' => $bond->id, 'member_id' => $member->id, 'plan_id' => $plan->id,
            'branch_id' => $this->hq->id, 'invoice_no' => $bond->invoice_no, 'amount' => $amount, 'start_date' => $start,
            'end_date' => Carbon::parse($start)->addMonthsNoOverflow((int) $plan->validity_months)->toDateString(), 'content' => '',
            'status' => $extra['status'] ?? 'active', 'settled_on' => $extra['settled_on'] ?? null]);
    }

    protected function qr(MemberContract $c, float $grams, float $cash, string $createdAt, string $status = 'pending'): RedeemableQr
    {
        $qr = RedeemableQr::create(['bond_id' => $c->bond_id, 'member_id' => $c->member_id, 'branch_id' => $c->branch_id, 'invoice_no' => $c->invoice_no,
            'qr_code' => 'QR'.random_int(100000, 999999).$c->id, 'qr_mode' => 'gold', 'gram_worth' => $grams, 'cash_worth' => $cash, 'status' => $status, 'qr_sent' => false]);
        RedeemableQr::whereKey($qr->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $qr->fresh();
    }

    public function test_report_splits_active_and_closed_contracts_with_settlements_per_scheme(): void
    {
        // Regional: one live dealership (billing QR = Spot Gold Stock) + one matured & cash-settled (2nd-year Gold Stock).
        $live = $this->contract($this->regional, 'REG1', 50000000, '2026-03-01');
        $this->qr($live, 2985.0746, 40000000, '2026-03-01 10:00:00');

        $done = $this->contract($this->regional, 'REG2', 50000000, '2024-01-10', ['status' => 'closed', 'settled_on' => '2026-02-01', 'bond_status' => 'closed']);
        $this->qr($done, 2985.0746, 40000000, '2024-01-10 10:00:00', 'redeemed');
        ContractSettlement::create(['member_contract_id' => $done->id, 'member_id' => $done->member_id, 'bond_id' => $done->bond_id,
            'amount' => 1250000, 'note' => 'expiry', 'paid_on' => '2026-02-01']);

        // Retailer: 12-month 70% settlement QR already issued (1-year Gold Stock), contract still active.
        $ret = $this->contract($this->retailer, 'RET1', 100000, '2025-04-01', ['settled_on' => '2026-04-01']);
        $this->qr($ret, 3.7313, 50000, '2025-04-01 10:00:00');          // billing allocation
        $this->qr($ret, 2.6119, 35000, '2026-04-01 09:00:00');          // settlement QR = 70% of the 50% contract share

        Livewire::test(BusinessModelReport::class)
            ->fillForm(['source' => 'next', 'from' => '2024-01-01', 'to' => '2026-09-16'])
            ->call('run')
            ->assertHasNoFormErrors()
            ->assertSee('1.1 Regional Dealership — scheme (P214)')
            ->assertSee('1.1 Regional Dealership — active contracts (bonds)')
            ->assertSee('1.1 Regional Dealership — closed contracts (bonds)')
            ->assertSee('1.2 Zonal Dealership — scheme (P207)')
            ->assertSee('2.2 G5 Retailer Plan — scheme (P201)')
            ->assertSee('LJC-REG1')
            ->assertSee('LJC-REG2')
            ->assertSee('12,50,000.00')      // 2nd-year Gold Stock paid
            ->assertSee('Issued 01 Apr 2026') // 1-year Gold Stock issued on the retailer contract
            ->assertSee('15,00,000.00')      // monthly sales limit surfaces on the retailer scheme
            ->assertSee('Started Amount')
            ->assertSee('Spot Gold Stock')
            ->assertSee('1-year Gold Stock')
            ->assertSee('2nd-year Gold Stock');

        // Section split: REG1 is active, REG2 is closed, and the closed block carries the settlement total.
        $component = Livewire::test(BusinessModelReport::class)->fillForm(['source' => 'next', 'from' => '2024-01-01', 'to' => '2026-09-16'])->call('run');
        $sections = collect($component->get('sections'))->keyBy('heading');

        $active = $sections['1.1 Regional Dealership — active contracts (bonds)'];
        $closed = $sections['1.1 Regional Dealership — closed contracts (bonds)'];
        $this->assertSame('1', $active['kv']['Contracts']);
        $this->assertSame('LJC-REG1', $active['rows'][0][0]);
        $this->assertSame('1', $closed['kv']['Contracts']);
        $this->assertSame('LJC-REG2', $closed['rows'][0][0]);
        $this->assertSame('₹12,50,000.00', $closed['kv']['2nd-year Gold Stock paid']);
        $this->assertSame('2,985.075 g / ₹4,00,00,000.00', $closed['kv']['Spot Gold Stock allocated']);

        $retail = $sections['2.2 G5 Retailer Plan — active contracts (bonds)'];
        $this->assertSame('2.612 g / ₹35,000.00', $retail['kv']['1-year Gold Stock issued']);
        $this->assertSame('3.731 g / ₹50,000.00', $retail['kv']['Spot Gold Stock allocated']);
    }

    public function test_plus1_block_counts_installments_and_100mg_allocations(): void
    {
        $c = $this->contract($this->plus1, 'RD1', 1500, '2026-05-01', ['bond_value' => 1380.20]);
        $this->qr($c, 0.1, 1380.20, '2026-05-01 10:00:00');
        foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $i => $on) {
            RdEntry::create(['bond_id' => $c->bond_id, 'member_id' => $c->member_id, 'paid_on' => $on, 'value' => 1380.20, 'due_count' => $i + 1, 'branch_id' => $this->hq->id]);
            $this->qr($c, 0.1, 1380.20, $on.' 10:00:00', $i === 0 ? 'redeemed' : 'pending');
        }

        $component = Livewire::test(BusinessModelReport::class)->fillForm(['source' => 'next', 'from' => '2026-01-01', 'to' => '2026-09-16'])->call('run');
        $sections = collect($component->get('sections'))->keyBy('heading');

        $summary = $sections['3.1 G11 Gold Savings Plan PLUS1 — installments & 100 mg gold allocations'];
        $this->assertSame('4', $summary['kv']['Installments received (as on To date)']);
        $this->assertSame('4', $summary['kv']['100 mg gold allocations']);
        $this->assertSame('0.400 g / ₹5,520.80', $summary['kv']['Spot Gold Stock allocated']);
        $this->assertSame('1', $summary['kv']['Allocations redeemed']);
        // Projected 1-year Gold Stock = collected so far (4 × 1,380.20) + 1 bonus month.
        $this->assertSame('₹6,901.00', $summary['kv']['1-year Gold Stock projected (not yet due)']);

        $active = $sections['3.1 G11 Gold Savings Plan PLUS1 — active contracts (bonds)'];
        $this->assertSame('LJC-RD1', $active['rows'][0][0]);
        $this->assertSame('4', $active['rows'][0][6]);
        $this->assertSame('4 of 11 paid', $active['rows'][0][7]);
        $this->assertSame('4 of 4 issued · ₹5,520.80', $active['rows'][0][13]);
        $this->assertSame('Projected · due 01 May 2027', $active['rows'][0][15]);
        $this->assertSame('Runs to 01 Apr 2027', $active['rows'][0][4]);

        // A To date before the renewals started sees only the joining installment.
        $early = collect(Livewire::test(BusinessModelReport::class)->fillForm(['source' => 'next', 'from' => '2026-01-01', 'to' => '2026-05-15'])->call('run')->get('sections'))->keyBy('heading');
        $this->assertSame('1', $early['3.1 G11 Gold Savings Plan PLUS1 — installments & 100 mg gold allocations']['kv']['Installments received (as on To date)']);
    }

    public function test_simplified_report_lists_only_closed_contracts_in_the_board_template(): void
    {
        $this->contract($this->regional, 'REG1', 50000000, '2026-03-01');            // active → excluded
        $zonal = Plan::where('code', 'P207')->first();
        $this->contract($zonal, 'ZON1', 10000000, '2024-02-01', ['status' => 'closed', 'settled_on' => '2026-02-01', 'bond_status' => 'closed']);
        $this->contract($zonal, 'ZON2', 10000000, '2026-02-01');                          // active → excluded

        $plus1 = $this->contract($this->plus1, 'RD9', 1500, '2025-01-01', ['bond_value' => 1500, 'status' => 'closed', 'settled_on' => '2026-01-01', 'bond_status' => 'closed']);
        foreach (range(1, 10) as $i) {
            RdEntry::create(['bond_id' => $plus1->bond_id, 'member_id' => $plus1->member_id, 'paid_on' => Carbon::parse('2025-01-01')->addMonths($i)->toDateString(),
                'value' => 1500, 'due_count' => $i, 'branch_id' => $this->hq->id]);
        }

        $sections = collect(Livewire::test(BusinessModelReport::class)
            ->fillForm(['mode' => 'lite', 'source' => 'next', 'from' => '2024-01-01', 'to' => '2026-09-17'])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');

        $this->assertSame('01-01-2024 - 17-09-2026', $sections['Lord Jeweller - Business Model Report']['kv']['Overview Period']);
        $this->assertFalse($sections->keys()->contains(fn ($h) => str_contains($h, 'active contracts')));

        $zonalBlock = $sections['Dealers (Offline-Dealers) — 1. Zonal'];
        $this->assertSame('80%', $zonalBlock['kv']['Gold Stock']);
        $this->assertSame('Unlimited', $zonalBlock['kv']['Sales Limit (Wholesale Price)']);
        $this->assertSame(['Gold Stock', 'Interior Works Allocation', 'Refundable Deposit', 'Sales Limit (Wholesale Price)', 'Distributors joined in period'], array_keys($zonalBlock['kv']));
        $this->assertSame('15L / Month', $sections['Dealers (Online-Dealers) — 2. Retailer']['kv']['Sales Limit (Wholesale Price)']);
        $this->assertArrayNotHasKey('Dealers (Online-Dealers) — 4. Sub Dealer', $sections->all());
        $this->assertArrayNotHasKey('Sales Limit (Wholesale Price)', $sections['Customer (Gold-Saving) — 1. G11 plus 1']['kv']);
        $this->assertSame('10%', $zonalBlock['kv']['Refundable Deposit']);
        $this->assertCount(2, $zonalBlock['rows'], 'dealer schemes list everyone who joined in the period, active or closed');
        $this->assertSame('2', $zonalBlock['kv']['Distributors joined in period']);
        $this->assertSame(['Dist ZON1 (ZON1)', '—', '01 Feb 2024', '01 Feb 2026', '1,00,00,000.00', '80,00,000.00'], $zonalBlock['rows'][0]);
        $this->assertSame('Dist ZON2 (ZON2)', $zonalBlock['rows'][1][0]);
        $this->assertSame(['Distributor Name (UID)', 'GST No', 'Start On', 'End On', 'Started Amount (₹)', 'Delivery Gold (₹)'], $zonalBlock['columns']);
        $this->assertSame(['Distributor Name (UID)', 'Start On', 'End On', 'Started Amount (₹)', 'Delivery Gold (₹)'], $sections['Customer (Gold-Saving) — 1. G11 plus 1']['columns']);

        $plus1Block = $sections['Customer (Gold-Saving) — 1. G11 plus 1'];
        $this->assertSame('100mg Gold Coin X 11 Month', $plus1Block['kv']['Security']);
        $this->assertCount(1, $plus1Block['rows'], 'G11 stays closed-only');
        $this->assertSame('18,000.00', $plus1Block['rows'][0][4]);   // 11 × 1,500 paid + 1 bonus month
        $this->assertArrayNotHasKey('Delivery Amount total', $plus1Block['kv']);
    }

    public function test_dealers_report_lists_dealer_logins_and_ignores_the_dates(): void
    {
        $taluk = Branch::create(['name' => 'Taluk One', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $this->hq->id, 'is_active' => true, 'incharge' => 'Ravi K', 'gst_no' => '33AAAAA0000A1Z5']);
        Plan::create(['code' => 'P203', 'hid' => 4, 'name' => ['en' => 'Taluka Dealership'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 500000, 'allocation_bv' => 20, 'allocation_cont' => 80, 'is_contract' => true, 'is_active' => true, 'validity_months' => 24]);
        $member = $this->member('TLK1');
        $member->forceFill(['joined_on' => '2025-05-10'])->save();
        $login = User::factory()->create(['name' => 'Taluk Login', 'status' => 'active', 'member_code' => 'TLK1', 'invested' => 500000]);
        $login->assignRole('distributor');
        $login->forceFill(['branch_id' => $taluk->id])->save();
        // Below the Taluk entry minimum (₹5,00,000) → not a Taluk dealer, left out.
        $small = User::factory()->create(['name' => 'Small Login', 'status' => 'active', 'member_code' => 'TLK0', 'invested' => 15000]);
        $small->assignRole('distributor');
        $small->forceFill(['branch_id' => $taluk->id])->save();

        // A closed PLUS1 bond started long before any sensible window — the Dealers Report has no window.
        $rd = $this->contract($this->plus1, 'RD8', 1500, '2023-01-01', ['bond_value' => 1500, 'status' => 'closed', 'settled_on' => '2024-01-01', 'bond_status' => 'closed']);

        $sections = collect(Livewire::test(BusinessModelReport::class)
            ->fillForm(['mode' => 'dealers', 'source' => 'next'])
            ->call('run')->assertHasNoFormErrors()
            ->get('sections'))->keyBy('heading');

        $this->assertSame(now()->format('d-m-Y'), $sections['Lord Jeweller - Dealers Report']['kv']['As on']);

        $talukBlock = $sections['Dealers (Offline-Dealers) — 3. Taluk'];
        $this->assertSame('1', $talukBlock['kv']['Dealers Count']);
        $this->assertArrayNotHasKey('Started Amount total', $talukBlock['kv']);
        // No dealership bond on record → invested is the Started Amount and Delivery Gold is the 80% share.
        $this->assertSame(['Dist TLK1 (TLK1) · Incharge: Ravi K', '33AAAAA0000A1Z5', '10 May 2025', '10 May 2027', '5,00,000.00', '4,00,000.00'], $talukBlock['rows'][0]);

        // With two dealership bonds the login shows their FULL value and the gold actually allocated.
        $member = Member::where('member_code', 'TLK1')->first();
        $talukPlan = Plan::where('code', 'P203')->first();
        foreach ([['TLKB1', 500000, '2025-06-01', 400000], ['TLKB2', 500000, '2026-01-01', 400000]] as [$code, $amt, $start, $gold]) {
            $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $talukPlan->id, 'branch_id' => $taluk->id, 'bond_date' => $start, 'value' => $amt, 'invoice_no' => 'INV-'.$code, 'status' => 'active']);
            MemberContract::create(['contract_no' => 'LJC-'.$code, 'bond_id' => $bond->id, 'member_id' => $member->id, 'plan_id' => $talukPlan->id, 'branch_id' => $taluk->id,
                'invoice_no' => $bond->invoice_no, 'amount' => $amt, 'start_date' => $start, 'end_date' => Carbon::parse($start)->addMonthsNoOverflow(24)->toDateString(), 'content' => '', 'status' => 'active']);
            RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $taluk->id, 'invoice_no' => $bond->invoice_no, 'qr_code' => 'Q'.$code, 'qr_mode' => 'gold', 'gram_worth' => 29.85, 'cash_worth' => $gold, 'status' => 'pending', 'qr_sent' => false]);
        }
        $again = collect(Livewire::test(BusinessModelReport::class)->fillForm(['mode' => 'dealers', 'source' => 'next'])->call('run')->get('sections'))->keyBy('heading');
        $this->assertSame(['Dist TLK1 (TLK1) · Incharge: Ravi K', '33AAAAA0000A1Z5', '01 Jun 2025', '01 Jun 2027', '10,00,000.00', '8,00,000.00'], $again['Dealers (Offline-Dealers) — 3. Taluk']['rows'][0]);

        $plus1Block = $sections['Customer (Gold-Saving) — 1. G11 plus 1'];
        $this->assertSame('1', $plus1Block['kv']['Closed contracts']);
        $this->assertSame('01 Jan 2023', $plus1Block['rows'][0][1]);
    }

    public function test_exports_download_and_dealers_cannot_open_it(): void
    {
        $this->contract($this->regional, 'REG9', 50000000, '2026-03-01');

        Livewire::test(BusinessModelReport::class)
            ->fillForm(['source' => 'next', 'from' => '2026-01-01', 'to' => '2026-09-16'])
            ->call('exportCsv')
            ->assertFileDownloaded();

        Livewire::test(BusinessModelReport::class)
            ->fillForm(['source' => 'next', 'from' => '2026-01-01', 'to' => '2026-09-16'])
            ->call('exportPdf')
            ->assertFileDownloaded();

        $this->assertTrue(BusinessModelReport::canAccess());

        $dealer = User::factory()->create(['status' => 'active']);
        $dealer->assignRole('distributor');
        $dealer->forceFill(['branch_id' => $this->hq->id])->save();
        $this->actingAs($dealer);
        $this->assertFalse(BusinessModelReport::canAccess());
    }
}
