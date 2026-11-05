<?php

namespace Tests\Feature;

use App\Filament\Pages\CommissionApproval;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\User;
use App\Services\CommissionApprovalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User 2026-11-05:
 *  1. the board's Sub Dealer scheme pays a CBC of 10 % × 24 months — the plan was 0 / 0, so the
 *     bonds already billed have no coupon: `cbc:schedule-missing` gives them the billing schedule;
 *  3. the dealerships' pending coupons and the Sub Dealers' are listed and paid on their own —
 *     the Scheme filter on Commission Approval and `commission:approve --plan=`.
 */
class SubDealerCbcTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $sub;

    protected Plan $taluka;

    protected Member $subMember;

    protected Member $talukaMember;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $rank = Rank::where('depth', 0)->value('id');
        $member = fn (string $code) => Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        $plan = fn (array $a) => Plan::create($a + ['plan_type' => 2, 'type' => 'digital', 'min_value' => 0, 'is_redeem' => true, 'is_contract' => true, 'is_active' => true, 'validity_months' => 24]);

        // as the migration leaves it: 10 % × 24, bond = 25 % of the bill
        $this->sub = $plan(['code' => 'T213', 'name' => ['en' => 'Sub Dealer'], 'hid' => 5, 'allocation_bv' => 75, 'allocation_cont' => 25, 'allocation' => 25, 'cbc_value' => 10, 'cbc_count' => 24]);
        $this->taluka = $plan(['code' => 'T203', 'name' => ['en' => 'Taluka Dealership'], 'hid' => 4, 'allocation_bv' => 20, 'allocation_cont' => 80, 'allocation' => 80, 'cbc_value' => 2, 'cbc_count' => 24, 'cbc_dealer_rule' => true]);

        // a legacy Sub Dealer bill of ₹1,00,000 on 26 Sep 2026 (bond = 25,000), billed with NO coupon
        $this->subMember = $member('LJWSUB1');
        Bond::create(['member_id' => $this->subMember->id, 'plan_id' => $this->sub->id, 'branch_id' => $branch->id, 'bond_date' => '2026-09-26',
            'value' => 25000, 'epin_value' => 100000, 'invoice_no' => '28113', 'cbc_value' => 0, 'cbc_count' => 0, 'cbc_issued' => 1, 'status' => 'active']);

        // a Taluka dealer whose November coupon (₹10,000) is waiting
        $this->talukaMember = $member('LJWTAL1');
        $tb = Bond::create(['member_id' => $this->talukaMember->id, 'plan_id' => $this->taluka->id, 'branch_id' => $branch->id, 'bond_date' => '2026-08-10',
            'value' => 400000, 'epin_value' => 500000, 'invoice_no' => '27000', 'cbc_value' => 10000, 'cbc_count' => 24, 'cbc_issued' => 24, 'status' => 'active']);
        CbcEntry::create(['member_id' => $this->talukaMember->id, 'bond_id' => $tb->id, 'code' => 'TALNOV', 'worth' => 10000, 'cbc_date' => '2026-11-01', 'status' => 'pending']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sub_dealer_bonds_get_the_ten_percent_schedule_once(): void
    {
        $bond = Bond::where('plan_id', $this->sub->id)->firstOrFail();

        // dry run: figures only
        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213']])
            ->expectsOutputToContain('1 active bond(s) without any coupon')
            ->expectsOutputToContain('coupon ₹10,000.00 every month')
            ->assertSuccessful();
        $this->assertSame(0, CbcEntry::where('bond_id', $bond->id)->count());

        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213'], '--apply' => true])->assertSuccessful();

        $coupons = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->get();
        $this->assertCount(24, $coupons);
        // bill × 10 % a month; the first on the 1st after the bill, prorated 5 days ÷ 30
        $this->assertSame('2026-10-01', $coupons[0]->cbc_date->toDateString());
        $this->assertEquals(1666.67, (float) $coupons[0]->worth);
        $this->assertSame('2026-11-01', $coupons[1]->cbc_date->toDateString());
        $this->assertEquals(10000.0, (float) $coupons[1]->worth);
        $this->assertSame('2028-09-01', $coupons[23]->cbc_date->toDateString());
        $this->assertTrue($coupons->every(fn ($c) => $c->status === 'pending' && (int) $c->member_id === (int) $this->subMember->id));
        $this->assertEquals([10000.0, 24, 24], [(float) $bond->fresh()->cbc_value, (int) $bond->fresh()->cbc_count, (int) $bond->fresh()->cbc_issued]);

        // a second run finds nothing left, and the monthly cut-off issues no extra coupon
        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213'], '--apply' => true])
            ->expectsOutputToContain('0 active bond(s) without any coupon')->assertSuccessful();
        app(\App\Services\CommissionService::class)->issueCbc(Carbon::parse('2026-12-01'));
        $this->assertSame(24, CbcEntry::where('bond_id', $bond->id)->count());

        // the Taluka dealer's own coupon was never touched
        $this->assertSame(1, CbcEntry::where('member_id', $this->talukaMember->id)->count());
    }

    public function test_a_scheme_without_cbc_is_refused(): void
    {
        $this->sub->update(['cbc_value' => 0, 'cbc_count' => 0]);

        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213'], '--apply' => true])
            ->expectsOutputToContain('has no CBC configured')->assertFailed();
        $this->assertSame(1, CbcEntry::count());
    }

    public function test_the_scheme_filter_lists_and_pays_only_the_chosen_schemes(): void
    {
        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213'], '--apply' => true])->assertSuccessful();
        $svc = app(CommissionApprovalService::class);

        // due today: Sub Dealer Oct + Nov, Taluka Nov
        $this->assertSame(3, $svc->query('CBC')->count());
        $this->assertSame(2, $svc->query('CBC', null, null, [$this->sub->id])->count());
        $this->assertSame(1, $svc->query('CBC', null, null, [$this->taluka->id])->count());
        $this->assertSame(3, $svc->query('CBC', null, null, [$this->sub->id, $this->taluka->id])->count());

        // shell: only the Taluka dealership — dealership rule: 10 % TDS withheld, ₹9,000 reaches the wallet
        $this->artisan('commission:approve', ['--type' => 'CBC', '--plan' => ['T203'], '--no-interaction' => true])
            ->expectsOutputToContain('1 un-paid rows')->expectsOutputToContain('Approved 1 rows')->assertSuccessful();
        $paid = CbcEntry::where('code', 'TALNOV')->firstOrFail();
        $this->assertSame('paid', $paid->status);
        $this->assertEquals([9000.0, 1000.0, 0.0, 0.0], [(float) $paid->cash_amount, (float) $paid->tds, (float) $paid->service_charge, (float) $paid->coupon_amount]);
        $this->assertEquals(9000.0, (float) MemberWallet::find($this->talukaMember->id)->cash_balance);
        $this->assertSame(2, CbcEntry::where('member_id', $this->subMember->id)->where('status', 'pending')->whereDate('cbc_date', '<=', '2026-11-05')->count());

        $this->artisan('commission:approve', ['--type' => 'IC', '--plan' => ['T203'], '--dry-run' => true])->assertFailed();
        $this->artisan('commission:approve', ['--type' => 'CBC', '--plan' => ['NOPE'], '--dry-run' => true])
            ->expectsOutputToContain('Unknown scheme code')->assertFailed();
    }

    public function test_the_approval_page_filters_by_scheme_and_shows_the_payout_split(): void
    {
        $this->artisan('cbc:schedule-missing', ['--plan' => ['T213'], '--apply' => true])->assertSuccessful();
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        $subRow = CbcEntry::where('member_id', $this->subMember->id)->min('id');
        $talukaRow = CbcEntry::where('member_id', $this->talukaMember->id)->min('id');

        // every scheme: both beneficiaries, each with its scheme and its own split rule
        Livewire::test(CommissionApproval::class)
            ->fillForm(['type' => 'CBC', 'from' => null, 'to' => '2026-11-05'])
            ->assertCanSeeTableRecords(CbcEntry::whereIn('id', [$subRow, $talukaRow])->get())
            ->assertSee('Sub Dealer')->assertSee('Taluka Dealership')
            ->assertSee('To cash wallet');

        // Sub Dealer only → the Taluka dealer is not listed, and approving pays the Sub Dealer alone
        Livewire::test(CommissionApproval::class)
            ->fillForm(['type' => 'CBC', 'from' => null, 'to' => '2026-11-05', 'plans' => [$this->sub->id]])
            ->assertCanSeeTableRecords(CbcEntry::whereIn('id', [$subRow])->get())
            ->assertCanNotSeeTableRecords(CbcEntry::whereIn('id', [$talukaRow])->get())
            ->callTableBulkAction('approve', [$subRow])
            ->assertNotified();

        $this->assertSame(2, CbcEntry::where('member_id', $this->subMember->id)->where('status', 'paid')->count());
        $this->assertSame('pending', CbcEntry::where('code', 'TALNOV')->value('status'));
        // member rule while the plan's dealership switch is off: 50 % of 1,666.67 + 10,000
        $this->assertEquals(5833.34, (float) MemberWallet::find($this->subMember->id)->cash_balance);
    }
}
