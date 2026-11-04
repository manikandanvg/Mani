<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Services\CommissionApprovalService;
use App\Services\RdCollectionService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User 2026-11-04 — "bill the legacy way": the bond is bill × plans.allocation, every
 * commission is a % of the FULL bill, the coupon is bill × cbc % every month, a Gold Saving
 * instalment pays the IC schedule again, and CBC is split in the legacy month-report format
 * (dealership plans 100 % cash with 10 % TDS; member plans 50 % cash + HQ coupon wallet).
 */
class LegacyBillingBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // HQ branch id=1, ranks
    }

    protected function member(string $code, ?int $uplineId): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'upline_id' => $uplineId, 'placement' => 'level',
            'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active',
        ]);
    }

    /** A Taluka-style plan: 80 % bond share, IC 2.5/0.75, level 0.5/0.2, CBC 2 % × 24. */
    protected function talukaPlan(bool $dealerRule = true): Plan
    {
        return Plan::create([
            'code' => 'TLK', 'name' => ['en' => 'Taluka Dealership'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 20, 'allocation_cont' => 80, 'allocation' => 80,
            'validity_months' => 24, 'cbc_value' => 2, 'cbc_count' => 24, 'cbc_dealer_rule' => $dealerRule,
            'ic_schedule' => ['2.5', '0.75'], 'level_schedule' => ['0.5', '0.2'],
            'level_depth' => 2, 'level_com_duration' => 24, 'billing_margin' => 0, 'is_active' => true,
        ]);
    }

    public function test_billing_uses_the_full_bill_and_the_legacy_bond_share(): void
    {
        $plan = $this->talukaPlan();
        $u1 = $this->member('U1', null);
        $u2 = $this->member('U2', $u1->id);
        // big active bonds so the earning-potential cap never binds
        foreach ([$u1, $u2] as $u) {
            Bond::create(['member_id' => $u->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 10_000_000, 'cbc_count' => 0, 'lvlcom_count' => 24, 'status' => 'active']);
        }
        $buyer = $this->member('B1', $u2->id);

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $plan->id,
            'bill_date' => '2026-11-04',
            'customer' => ['member_id' => $buyer->id],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 500000, 'grand_total' => 515000]],
        ]);

        $bond = Bond::where('member_id', $buyer->id)->firstOrFail();
        $this->assertEquals(400000.0, (float) $bond->value, 'bond = bill × 80 % (legacy bvalue)');
        $this->assertEquals([2500.0, 1000.0], array_map('floatval', $bond->lvlcom), 'level amounts = bill × %');
        $this->assertEquals(10000.0, (float) $bond->cbc_value, 'coupon = bill × 2 % every month');

        $ic = CommissionLedger::where('bond_id', $bond->id)->where('type', 'IC')->orderBy('level')->pluck('amount', 'level');
        $this->assertEquals(12500.0, (float) $ic[1], 'IC level 1 = bill × 2.5 %');
        $this->assertEquals(3750.0, (float) $ic[2], 'IC level 2 = bill × 0.75 %');

        $coupons = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->get();
        $this->assertCount(24, $coupons);
        $this->assertEquals('2026-12-01', $coupons->first()->cbc_date->toDateString());
        $this->assertEquals(round(10000 / 30 * 27, 2), (float) $coupons->first()->worth, 'first coupon prorated by days (4 Nov → 1 Dec = 27)');
        $this->assertEquals(10000.0, (float) $coupons[1]->worth);

        // legacy mybv / mygbv grow by the grand total as billed (epinvalue, tax included), not the bond share
        $this->assertEquals(515000.0, (float) $bond->epin_value);
        $this->assertEquals(515000.0, (float) $buyer->fresh()->bv);
        $this->assertEquals(515000.0, (float) $u2->fresh()->gbv);
    }

    public function test_cbc_split_follows_the_legacy_month_report(): void
    {
        $dealerPlan = $this->talukaPlan(true);
        $memberPlan = Plan::create([
            'code' => 'G5T', 'name' => ['en' => 'G5 Retailer Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation' => 50, 'allocation_bv' => 50, 'validity_months' => 24,
            'cbc_value' => 6, 'cbc_count' => 24, 'cbc_dealer_rule' => false,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 24, 'billing_margin' => 0, 'is_active' => true,
        ]);
        $dealer = $this->member('D1', null);
        $retailer = $this->member('R1', null);
        $db = Bond::create(['member_id' => $dealer->id, 'plan_id' => $dealerPlan->id, 'bond_date' => now(), 'value' => 400000, 'cbc_count' => 24, 'lvlcom_count' => 24, 'status' => 'active']);
        $rb = Bond::create(['member_id' => $retailer->id, 'plan_id' => $memberPlan->id, 'bond_date' => now(), 'value' => 50000, 'cbc_count' => 24, 'lvlcom_count' => 24, 'status' => 'active']);
        CbcEntry::create(['member_id' => $dealer->id, 'bond_id' => $db->id, 'code' => 'D000001', 'worth' => 10000, 'cbc_date' => '2026-11-01', 'status' => 'pending']);
        CbcEntry::create(['member_id' => $retailer->id, 'bond_id' => $rb->id, 'code' => 'R000001', 'worth' => 6000, 'cbc_date' => '2026-11-01', 'status' => 'pending']);

        // the aggregate split the Payout Run / approve command show
        $totals = CommissionApprovalService::cbcSplitTotals(CbcEntry::query()->where('status', 'pending'));
        $this->assertEquals(10000.0, $totals['dealer_worth']);
        $this->assertEquals(6000.0, $totals['member_worth']);
        $this->assertEquals(10000 + 3000, $totals['cash']);          // dealer 100 % + member 50 %
        $this->assertEquals(1000 + 300, $totals['tds']);             // dealer 10 % + member 5 %
        $this->assertEquals(0 + 300, $totals['service']);            // dealer none + member 5 %
        $this->assertEquals(0 + 2400, $totals['coupon']);            // HQ coupon wallet: member plans only

        app(CommissionApprovalService::class)->approveBatch('CBC', '2026-11-01', '2026-11-01');

        $d = CbcEntry::where('bond_id', $db->id)->first();
        $this->assertEquals('paid', $d->status);
        $this->assertEquals(10000.0, (float) $d->cash_amount, 'dealership plan: 100 % cash');
        $this->assertEquals(1000.0, (float) $d->tds, 'dealership plan: 10 % TDS');
        $this->assertEquals(0.0, (float) $d->service_charge);
        $this->assertEquals(0.0, (float) $d->coupon_amount);
        $this->assertEquals(10000.0, (float) MemberWallet::where('member_id', $dealer->id)->value('cash_balance'));

        $r = CbcEntry::where('bond_id', $rb->id)->first();
        $this->assertEquals(3000.0, (float) $r->cash_amount, 'member plan: 50 % cash');
        $this->assertEquals(300.0, (float) $r->tds);
        $this->assertEquals(300.0, (float) $r->service_charge);
        $this->assertEquals(2400.0, (float) $r->coupon_amount, 'member plan: 40 % to the HQ coupon wallet figure');
        $this->assertEquals(3000.0, (float) MemberWallet::where('member_id', $retailer->id)->value('cash_balance'));
        $this->assertEquals(0.0, (float) (MemberWallet::where('member_id', $retailer->id)->value('coupon_balance') ?? 0), 'never posted to the member coupon wallet');
    }

    public function test_gold_saving_instalment_pays_the_ic_schedule_again(): void
    {
        $rd = Plan::create([
            'code' => 'PL2', 'name' => ['en' => 'G11 Gold Savings Plan PLUS2'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 1500, 'allocation' => 100, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => ['10', '3'], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 10, 'renewal_margin' => 10, 'is_active' => true,
        ]);
        $u1 = $this->member('U1', null);
        $u2 = $this->member('U2', $u1->id);
        foreach ([$u1, $u2] as $u) {
            Bond::create(['member_id' => $u->id, 'plan_id' => $rd->id, 'bond_date' => now(), 'value' => 100000, 'cbc_count' => 0, 'lvlcom_count' => 0, 'status' => 'active']);
        }
        $saver = $this->member('S1', $u2->id);
        $bond = Bond::create(['member_id' => $saver->id, 'plan_id' => $rd->id, 'branch_id' => 1, 'bond_date' => '2026-10-04',
            'value' => 1500, 'epin_value' => 1545, 'cbc_count' => 0, 'lvlcom_count' => 0, 'status' => 'active']);

        app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => 1, 'amount' => 1500, 'paid_on' => '2026-11-04']);

        $ic = CommissionLedger::where('bond_id', $bond->id)->where('type', 'IC')->orderBy('level')->get();
        $this->assertCount(2, $ic);
        $this->assertEquals(154.5, (float) $ic[0]->amount, 'level 1 = 10 % of the bond epin value (legacy epinvalue)');
        $this->assertEquals($u2->id, $ic[0]->member_id);
        $this->assertEquals(46.35, (float) $ic[1]->amount);
        $this->assertEquals('pending', $ic[0]->status);
    }
}
