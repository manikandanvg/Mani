<?php

namespace Tests\Feature;

use App\Filament\Resources\PayoutRunResource\Pages\ListPayoutRuns;
use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\PayoutRun;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionApprovalService;
use App\Services\Payout\CutoffService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Monthly cut-off, legacy Ascript parity (docs/CUTOFF-PLAN.md): the bond's stored level
 * amounts, rank-depth qualification, bonds up to the cut-off only, pending until approved,
 * approval → cash wallet with the ₹14,050 slab judged on the member's month total.
 */
class PayoutCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Services\Activity\ActivityLogger::$enabled = true;
    }

    protected function member(string $code, ?int $upline, int $depth): Member
    {
        $m = Member::create([
            'member_code' => $code, 'name' => 'Name ' . $code, 'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(), 'upline_id' => $upline, 'placement' => 'level',
            'rank_id' => Rank::where('depth', $depth)->firstOrFail()->id, 'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $m->id]);

        return $m;
    }

    protected function plan(): Plan
    {
        return Plan::create([
            'code' => 'P' . random_int(1000, 9999), 'name' => ['en' => 'Sub Dealer'], 'type' => 'rd', 'min_value' => 1000,
            'allocation_bv' => 100, 'validity_months' => 24, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => ['10'], 'level_schedule' => ['2.0', '0.80', '0.40', '0.40', '0.40'], 'epin_count' => 0, 'is_active' => true,
        ]);
    }

    protected function bond(Member $m, Plan $plan, string $date, array $lvlcom, array $extra = []): Bond
    {
        return Bond::create(array_merge([
            'member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => $date, 'value' => 15000,
            'lvlcom' => $lvlcom, 'lvlcom_count' => 24, 'lvlcom_issued' => 0,
            'cbc_value' => 0, 'cbc_count' => 0, 'epin_value' => 0, 'status' => 'active',
        ], $extra));
    }

    public function test_cutoff_pays_the_bonds_stored_level_amounts_to_qualified_uplines_only(): void
    {
        $plan = $this->plan();
        // chain: top (depth 5) ← l4 (depth 4) ← l3 (depth 1: fails level 3) ← l2 (depth 2) ← l1 (depth 1) ← seller
        $top = $this->member('TOP', null, 5);
        $l4 = $this->member('L4', $top->id, 4);
        $l3 = $this->member('L3', $l4->id, 1);
        $l2 = $this->member('L2', $l3->id, 2);
        $l1 = $this->member('L1', $l2->id, 1);
        $seller = $this->member('SELLER', $l1->id, 0);

        // legacy figures on the bond (400/160/80/80/80) ≠ plan % × value (300/120/60/60/60)
        $bond = $this->bond($seller, $plan, '2026-11-20', [400, 160, 80, 80, 80]);
        $late = $this->bond($seller, $plan, '2026-12-01', [400, 160, 80, 80, 80]);   // after the cut-off
        $zero = $this->bond($seller, $plan, '2026-11-01', [0, 0, 0, 0, 0]);          // no level amounts

        $run = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);

        $this->assertSame('2026-12-01', $run->period->toDateString());
        $this->assertSame(1, $run->gap_bonds);
        $this->assertSame(4, $run->gap_rows, 'level 3 upline has depth 1 → not paid');
        $this->assertEquals(400 + 160 + 80 + 80, (float) $run->gap_gross);
        $this->assertSame(4, $run->gap_members);
        $this->assertSame('generated', $run->status);

        $rows = CommissionLedger::where('payout_run_id', $run->id)->orderBy('level')->get();
        $this->assertSame([1, 2, 4, 5], $rows->pluck('level')->map(fn ($l) => (int) $l)->all());
        $this->assertSame([$l1->id, $l2->id, $l4->id, $top->id], $rows->pluck('member_id')->map(fn ($l) => (int) $l)->all());
        $this->assertEquals([400.0, 160.0, 80.0, 80.0], $rows->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'pending' && $r->earned_on->toDateString() === '2026-12-01' && (int) $r->bond_id === $bond->id));
        $this->assertEquals(0, MemberWallet::where('member_id', $l1->id)->value('cash_balance'), 'nothing credited before approval');

        $this->assertSame(1, (int) $bond->fresh()->lvlcom_issued);
        $this->assertSame(0, (int) $late->fresh()->lvlcom_issued, 'bond after the cut-off untouched');
        $this->assertSame(0, (int) $zero->fresh()->lvlcom_issued, 'bond without level amounts untouched');
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($run->log_path));
        $this->assertStringContainsString('GAP  L1 EARN Rs.400.00', \Illuminate\Support\Facades\Storage::disk('local')->get($run->log_path));

        // re-running the same month changes nothing (one instalment per bond per month)
        $again = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);
        $this->assertSame(0, $again->gap_rows);
        $this->assertSame(1, (int) $bond->fresh()->lvlcom_issued);

        // next month pays instalment 2 of the November bond AND instalment 1 of the December one
        $dec = app(CutoffService::class)->run(Carbon::parse('2026-12-31'), [], null);
        $this->assertSame(2, $dec->gap_bonds);
        $this->assertSame(2, (int) $bond->fresh()->lvlcom_issued);
        $this->assertSame(1, (int) $late->fresh()->lvlcom_issued);
    }

    public function test_level_one_goes_to_the_upline_frozen_on_the_contract_not_the_current_one(): void
    {
        $plan = $this->plan();
        $oldUp = $this->member('OLDUP', null, 2);
        $oldTop = $this->member('OLDTOP', null, 3);
        $oldUp->update(['upline_id' => $oldTop->id]);
        $newUp = $this->member('NEWUP', null, 2);
        $seller = $this->member('S3', $oldUp->id, 0);
        $bond = $this->bond($seller, $plan, '2026-11-10', [400, 160, 0, 0, 0], ['upline_member_id' => $oldUp->id]);
        $seller->update(['upline_id' => $newUp->id]);   // re-mapped after the sale

        $run = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);
        $rows = CommissionLedger::where('payout_run_id', $run->id)->orderBy('level')->get();
        $this->assertSame([$oldUp->id, $oldTop->id], $rows->pluck('member_id')->map(fn ($v) => (int) $v)->all(), 'legacy: bond upline, then its chain');
        $this->assertSame(0, CommissionLedger::where('member_id', $newUp->id)->count());
    }

    public function test_level_amounts_are_whole_rupees_like_the_legacy_register(): void
    {
        $plan = $this->plan();
        $up = $this->member('RUP', null, 2);
        $seller = $this->member('S4', $up->id, 0);
        $this->bond($seller, $plan, '2026-11-10', [2500.5, 0, 0, 0, 0]);
        $this->bond($seller, $plan, '2026-11-11', [1250.25, 0, 0, 0, 0]);

        $run = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);
        $this->assertEquals([1250.0, 2501.0], CommissionLedger::where('payout_run_id', $run->id)->orderBy('amount')->pluck('amount')->map(fn ($a) => (float) $a)->all());
    }

    public function test_dry_run_writes_nothing_and_plan_percentages_are_the_fallback(): void
    {
        $plan = $this->plan();
        $up = $this->member('UP', null, 1);
        $seller = $this->member('S2', $up->id, 0);
        $bond = $this->bond($seller, $plan, '2026-11-10', []);   // no stored amounts → 2 % of 15,000 = 300 at level 1

        $this->artisan('payout:cutoff --cutoff=2026-11-30 --dry-run --quiet-log')
            ->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, PayoutRun::count());
        $this->assertSame(0, CommissionLedger::count());
        $this->assertSame(0, (int) $bond->fresh()->lvlcom_issued);

        $this->artisan('payout:cutoff --cutoff=2026-11-30 --quiet-log')->assertSuccessful();
        $run = PayoutRun::firstOrFail();
        $this->assertSame(1, $run->gap_rows);
        $this->assertEquals(300.0, (float) CommissionLedger::firstOrFail()->amount);
        $this->assertTrue(\App\Models\ActivityLog::where('action', 'cutoff_run')->exists());
    }

    public function test_approve_run_credits_cash_wallets_with_the_slab_on_the_month_total(): void
    {
        $plan = $this->plan();
        $big = $this->member('BIG', null, 1);      // month total 15,000 ≥ slab → 20 %
        $small = $this->member('SMALL', null, 1);  // month total 400 → 10 %
        $s1 = $this->member('S1', $big->id, 0);
        $s2 = $this->member('S2', $small->id, 0);
        foreach (range(1, 3) as $i) {
            $this->bond($s1, $plan, '2026-11-0' . $i, [5000, 0, 0, 0, 0]);
        }
        $this->bond($s2, $plan, '2026-11-05', [400, 0, 0, 0, 0]);

        // legacy slab: ≥ ₹14,050 → 20 % in all, else 10 % (5 + 5)
        Setting::updateOrCreate(['group' => 'commission', 'key' => 'GAP'], ['value' => json_encode([
            'tds' => 5, 'service' => 5, 'slab_from' => 14050, 'slab_tds' => 10, 'slab_service' => 10,
        ]), 'type' => 'json']);
        CommissionApprovalService::forgetCharges();

        $run = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);
        $this->assertSame(4, $run->gap_rows);

        $summary = collect($run->memberSummary())->keyBy('member_code');
        $this->assertEquals(15000.0, $summary['BIG']['gross']);
        $this->assertEquals(1500.0, $summary['BIG']['tds']);
        $this->assertEquals(12000.0, $summary['BIG']['net']);
        $this->assertEquals(400.0, $summary['SMALL']['gross']);
        $this->assertEquals(360.0, $summary['SMALL']['net']);

        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($admin);
        $r = app(CutoffService::class)->approve($run, $admin->id);
        $this->assertSame(4, $r['gap']);
        $this->assertSame(2, $r['members']);
        $this->assertSame('approved', $run->fresh()->status);
        $this->assertEquals(12000.0, (float) MemberWallet::where('member_id', $big->id)->value('cash_balance'), '3 × 5,000 less 20 % — slab on the month total, not per row');
        $this->assertEquals(15000.0, (float) MemberWallet::where('member_id', $big->id)->value('earning_total'));
        $this->assertEquals(360.0, (float) MemberWallet::where('member_id', $small->id)->value('cash_balance'));
        $this->assertSame(0, CommissionLedger::where('payout_run_id', $run->id)->where('status', '!=', 'paid')->count());
        $this->assertSame(0, $r['cbc']);

        // second approval is a no-op; the viewer lists the run for HQ
        $this->assertSame(0, app(CutoffService::class)->approve($run->fresh(), $admin->id)['gap']);
        $this->get('/admin/payout-runs')->assertSuccessful();
        Livewire::test(ListPayoutRuns::class)->assertCanSeeTableRecords([$run]);
        $this->get('/admin/payout-runs/' . $run->id)->assertSuccessful()->assertSee('Name BIG');
    }

    public function test_cbc_coupons_due_in_the_pay_month_are_tagged_and_pass_on_approval(): void
    {
        $plan = $this->plan();
        $m = $this->member('CB', null, 0);
        $bond = $this->bond($m, $plan, '2026-06-01', [0, 0, 0, 0, 0], ['cbc_value' => 1000, 'cbc_count' => 12, 'cbc_issued' => 12, 'lvlcom_count' => 0]);
        // legacy-style pre-scheduled coupons: one due in the pay month, one later
        $due = CbcEntry::create(['bond_id' => $bond->id, 'member_id' => $m->id, 'cbc_date' => '2026-12-01', 'code' => 'C-DEC', 'worth' => 1000, 'status' => 'pending']);
        $later = CbcEntry::create(['bond_id' => $bond->id, 'member_id' => $m->id, 'cbc_date' => '2027-01-01', 'code' => 'C-JAN', 'worth' => 1000, 'status' => 'pending']);

        $run = app(CutoffService::class)->run(Carbon::parse('2026-11-30'), [], null);
        $this->assertSame(1, $run->cbc_rows);
        $this->assertEquals(1000.0, (float) $run->cbc_worth);
        $this->assertSame($run->id, (int) $due->fresh()->payout_run_id);
        $this->assertNull($later->fresh()->payout_run_id);

        app(CutoffService::class)->approve($run);
        $this->assertSame('paid', $due->fresh()->status);
        $this->assertSame('pending', $later->fresh()->status);
        $w = MemberWallet::where('member_id', $m->id)->first();
        // board 2026-11-01 split of the ₹1,000 coupon: 50 % sales profit → cash, 5 + 5 % withheld, rest → coupon wallet (HQ reference)
        $this->assertEquals(500.0, (float) $w->cash_balance);
        $this->assertEquals(400.0, (float) $w->coupon_balance);
        $this->assertEquals(0.0, (float) $w->epin_balance, 'no EPIN wallet in the new system');
        $this->assertEquals(1000.0, (float) $w->earning_total);
        $due->refresh();
        $this->assertEquals(500.0, (float) $due->cash_amount);
        $this->assertEquals(50.0, (float) $due->tds);
        $this->assertEquals(50.0, (float) $due->service_charge);
        $this->assertEquals(400.0, (float) $due->coupon_amount);
    }
    /** Go-live 2026-11-01: the cut-off can be generated from the Payout Runs screen, once per month. */
    public function test_generate_button_runs_the_cutoff_once_per_pay_month(): void
    {
        $plan = $this->plan();
        $top = $this->member('TOP', null, 5);
        $mid = $this->member('MID', $top->id, 5);
        $this->bond($mid, $plan, '2026-10-20', [300, 120, 60, 60, 60]);

        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/login');

        Livewire::test(ListPayoutRuns::class)
            ->assertActionVisible('generate')
            ->callAction('generate', data: ['cutoff' => '2026-10-31'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $run = PayoutRun::firstOrFail();
        $this->assertSame('generated', $run->status);
        $this->assertSame('2026-11-01', $run->period->toDateString());
        $this->assertSame($admin->id, $run->run_by);
        $this->assertSame(1, $run->gap_rows);
        $this->assertEquals(300.0, (float) CommissionLedger::where('type', 'GAP')->where('member_id', $top->id)->value('amount'));
        $this->assertSame('pending', CommissionLedger::where('type', 'GAP')->value('status'), 'nothing credited until Approve run');

        // same month again → refused, no twin run
        Livewire::test(ListPayoutRuns::class)
            ->callAction('generate', data: ['cutoff' => '2026-10-31'])
            ->assertHasNoActionErrors();
        $this->assertSame(1, PayoutRun::count());

        // staff without super-admin never see the button
        $staff = User::create(['name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'secret123', 'status' => 'active']);
        $staff->assignRole('manager');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($staff);
        Livewire::test(ListPayoutRuns::class)->assertActionHidden('generate');
    }
}

