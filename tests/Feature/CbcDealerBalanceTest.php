<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User 2026-11-05: on live the dealers' CBC was already approved by the member split (50 % to
 * the cash wallet) before the dealership rule existed. Their "pending CBC" is the balance up
 * to the dealership rule (whole coupon less 10 % TDS) — `cbc:dealer-balance` reports and pays it.
 */
class CbcDealerBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function coupon(Plan $plan, string $code, array $attrs): CbcEntry
    {
        static $branch = null;
        $branch = Branch::first() ?? Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => 'Member', 'depth' => 0, 'is_active' => true]);
        $m = Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank->id]);
        $bond = Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-08-10',
            'value' => 400000, 'invoice_no' => 'L' . $code, 'cbc_value' => 10000, 'cbc_count' => 24, 'cbc_issued' => 24, 'status' => 'active']);

        return CbcEntry::create(['member_id' => $m->id, 'bond_id' => $bond->id, 'code' => 'C' . $code, 'worth' => 10000, 'cbc_date' => '2026-11-01'] + $attrs);
    }

    public function test_dealers_paid_fifty_percent_get_the_balance_up_to_the_dealership_rule_once(): void
    {
        $plan = fn (string $code, bool $dealer) => Plan::create(['code' => $code, 'name' => ['en' => $code], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 0,
            'allocation' => 80, 'allocation_bv' => 20, 'allocation_cont' => 80, 'cbc_value' => 2, 'cbc_count' => 24, 'cbc_dealer_rule' => $dealer, 'is_active' => true, 'validity_months' => 24]);
        $taluka = $plan('TLK', true);
        $g5 = $plan('G5X', false);
        $memberSplit = ['status' => 'paid', 'paid_on' => '2026-11-02', 'cash_amount' => 5000, 'tds' => 500, 'service_charge' => 500, 'coupon_amount' => 4000];

        $dealer = $this->coupon($taluka, 'D1', $memberSplit);                                   // paid 50 % → owed 4,000
        MemberWallet::create(['member_id' => $dealer->member_id, 'cash_balance' => 5000, 'earning_total' => 10000]);
        $retailer = $this->coupon($g5, 'R1', $memberSplit);                                     // member scheme: 50 % is right
        $legacy = $this->coupon($taluka, 'D2', ['status' => 'paid', 'paid_on' => '2026-09-01']);   // paid in legacy: no split on the row
        $full = $this->coupon($taluka, 'D3', ['status' => 'paid', 'paid_on' => '2026-11-04', 'cash_amount' => 10000, 'tds' => 1000]);   // credited in full on 4 Nov
        $unpaid = $this->coupon($taluka, 'D4', ['status' => 'pending']);                        // still on Commission Approval

        // report: nothing written
        $this->artisan('cbc:dealer-balance')
            ->expectsOutputToContain('1 coupon(s) of 1 dealer(s)')
            ->expectsOutputToContain('BALANCE TO PAY ₹4,000.00')
            ->expectsOutputToContain('credited ABOVE the dealership rule')
            ->assertSuccessful();
        $this->assertEquals(5000.0, (float) MemberWallet::find($dealer->member_id)->cash_balance);

        $this->artisan('cbc:dealer-balance', ['--apply' => true])->expectsOutputToContain('Credited ₹4,000.00 to 1 dealer wallet(s)')->assertSuccessful();

        $w = MemberWallet::find($dealer->member_id);
        $this->assertEquals(9000.0, (float) $w->cash_balance);         // 5,000 + 4,000 = the whole coupon less 10 % TDS
        $this->assertEquals(10000.0, (float) $w->earning_total);       // gross earned was already counted at approval
        $d = $dealer->fresh();
        $this->assertEquals([9000.0, 1000.0, 0.0, 0.0], [(float) $d->cash_amount, (float) $d->tds, (float) $d->service_charge, (float) $d->coupon_amount]);

        // nobody else moved
        $this->assertEquals(5000.0, (float) $retailer->fresh()->cash_amount);
        $this->assertNull(MemberWallet::find($retailer->member_id));
        $this->assertEquals(0.0, (float) $legacy->fresh()->cash_amount);
        $this->assertNull(MemberWallet::find($legacy->member_id));
        $this->assertEquals(10000.0, (float) $full->fresh()->cash_amount);
        $this->assertSame('pending', $unpaid->fresh()->status);

        // a second run pays nothing twice
        $this->artisan('cbc:dealer-balance', ['--apply' => true])->expectsOutputToContain('0 coupon(s) of 0 dealer(s)')->assertSuccessful();
        $this->assertEquals(9000.0, (float) MemberWallet::find($dealer->member_id)->cash_balance);
    }

    public function test_the_window_limits_which_coupons_are_settled(): void
    {
        $taluka = Plan::create(['code' => 'TLK', 'name' => ['en' => 'Taluka'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 0,
            'allocation' => 80, 'cbc_value' => 2, 'cbc_count' => 24, 'cbc_dealer_rule' => true, 'is_active' => true, 'validity_months' => 24]);
        $c = $this->coupon($taluka, 'D1', ['status' => 'paid', 'paid_on' => '2026-11-02', 'cash_amount' => 5000, 'tds' => 500, 'service_charge' => 500, 'coupon_amount' => 4000]);

        $this->artisan('cbc:dealer-balance', ['--from' => '2026-12-01', '--apply' => true])->expectsOutputToContain('0 coupon(s)')->assertSuccessful();
        $this->assertEquals(5000.0, (float) $c->fresh()->cash_amount);

        $this->artisan('cbc:dealer-balance', ['--from' => '2026-11-01', '--to' => '2026-11-30', '--apply' => true])->assertSuccessful();
        $this->assertEquals(9000.0, (float) $c->fresh()->cash_amount);
        $this->assertEquals(4000.0, (float) MemberWallet::find($c->member_id)->cash_balance);
    }
}
