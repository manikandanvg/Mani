<?php

namespace Tests\Feature\Api;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 — "My Business" distributor endpoints (dashboard, downline, earnings, bonds).
 */
class MemberBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'UP1', 'name' => 'Upline', 'phone' => '9000000001', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active', 'bv' => 1000, 'gbv' => 5000, 'downline_count' => 3,
        ]);
        MemberWallet::create(['member_id' => $this->member->id, 'currency_code' => 'INR', 'cash_balance' => 1500, 'epin_balance' => 0, 'coupon_balance' => 0, 'earning_total' => 2000]);
    }

    protected function actingAsMember(): void
    {
        Sanctum::actingAs($this->member, ['*']);
    }

    public function test_dashboard_requires_member_token(): void
    {
        // A retail customer cannot access the distributor area.
        Sanctum::actingAs(Customer::create(['phone' => '9111111111']), ['*']);
        $this->getJson('/api/v1/member/dashboard')->assertStatus(403);

        $this->getJson('/api/v1/member/dashboard')->assertStatus(403);
    }

    public function test_dashboard_aggregates_network_earnings_and_bonds(): void
    {
        $this->actingAsMember();
        $down = Member::create(['member_code' => 'D1', 'name' => 'Down', 'phone' => '9000000002', 'joined_on' => now(), 'upline_id' => $this->member->id, 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        $plan = Plan::create(['code' => 'P1', 'name' => ['en' => 'Plan'], 'plan_type' => 1, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);
        Bond::create(['member_id' => $this->member->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 10000, 'status' => 'active', 'invoice_no' => 'INV1']);
        CommissionLedger::create(['type' => 'IC', 'member_id' => $this->member->id, 'from_member_id' => $down->id, 'amount' => 500, 'status' => 'paid', 'earned_on' => now(), 'invoice_no' => 'INV1']);
        CommissionLedger::create(['type' => 'IC', 'member_id' => $this->member->id, 'amount' => 200, 'status' => 'pending', 'earned_on' => now(), 'invoice_no' => 'INV2']);

        $res = $this->getJson('/api/v1/member/dashboard')->assertOk()
            ->assertJsonPath('member.member_code', 'UP1')
            ->assertJsonPath('network.direct_downlines', 1)
            ->assertJsonPath('network.team_size', 3)
            ->assertJsonPath('wallet.cash_balance', 1500)
            ->assertJsonPath('bonds.active', 1);

        $this->assertEquals(700.0, $res->json('earnings.ic.total'));
        $this->assertEquals(500.0, $res->json('earnings.ic.paid'));
        $this->assertEquals(200.0, $res->json('earnings.ic.pending'));
        $this->assertEquals(10000.0, $res->json('bonds.invested'));
    }

    public function test_downline_lists_directs_with_counts(): void
    {
        $this->actingAsMember();
        Member::create(['member_code' => 'D1', 'name' => 'A', 'phone' => '9000000003', 'joined_on' => now(), 'upline_id' => $this->member->id, 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        Member::create(['member_code' => 'D2', 'name' => 'B', 'phone' => '9000000004', 'joined_on' => now(), 'upline_id' => $this->member->id, 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'inactive']);
        // someone else's downline must not appear
        $other = Member::create(['member_code' => 'OT', 'name' => 'Other', 'phone' => '9000000005', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);
        Member::create(['member_code' => 'D3', 'name' => 'C', 'phone' => '9000000006', 'joined_on' => now(), 'upline_id' => $other->id, 'placement' => 'level', 'rank_id' => $this->member->rank_id, 'status' => 'active']);

        $res = $this->getJson('/api/v1/member/downline')->assertOk()
            ->assertJsonPath('counts.direct', 2)
            ->assertJsonPath('counts.active', 1);
        $codes = collect($res->json('direct'))->pluck('member_code');
        $this->assertEqualsCanonicalizing(['D1', 'D2'], $codes->all());
    }

    public function test_earnings_list_filters_by_stream(): void
    {
        $this->actingAsMember();
        CommissionLedger::create(['type' => 'IC', 'member_id' => $this->member->id, 'amount' => 500, 'status' => 'paid', 'earned_on' => now(), 'invoice_no' => 'A']);
        CommissionLedger::create(['type' => 'GAP', 'member_id' => $this->member->id, 'amount' => 300, 'status' => 'paid', 'earned_on' => now(), 'invoice_no' => 'B']);
        $bond = Bond::create(['member_id' => $this->member->id, 'plan_id' => Plan::create(['code' => 'P2', 'name' => ['en' => 'P2'], 'plan_type' => 1, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true])->id, 'bond_date' => now(), 'value' => 1000, 'status' => 'active', 'invoice_no' => 'C']);
        CbcEntry::create(['bond_id' => $bond->id, 'member_id' => $this->member->id, 'cbc_date' => now(), 'code' => 'CB1', 'worth' => 250, 'status' => 'pending']);

        $ic = $this->getJson('/api/v1/member/earnings?stream=IC')->assertOk();
        $this->assertEquals(['IC'], collect($ic->json('data'))->pluck('stream')->unique()->all());
        $this->assertEquals(500.0, $ic->json('data.0.amount'));

        $cbc = $this->getJson('/api/v1/member/earnings?stream=CBC')->assertOk();
        $this->assertEquals('CBC', $cbc->json('data.0.stream'));
        $this->assertEquals(250.0, $cbc->json('data.0.amount'));
    }

    public function test_earnings_summary_totals(): void
    {
        $this->actingAsMember();
        CommissionLedger::create(['type' => 'GAP', 'member_id' => $this->member->id, 'amount' => 300, 'status' => 'paid', 'earned_on' => now(), 'invoice_no' => 'B']);

        $this->getJson('/api/v1/member/earnings/summary')->assertOk()
            ->assertJsonPath('gap.total', 300)
            ->assertJsonPath('gap.paid', 300)
            ->assertJsonStructure(['ic' => ['total', 'paid', 'pending'], 'gap', 'cbc', 'reseller']);
    }

    public function test_bonds_list(): void
    {
        $this->actingAsMember();
        $plan = Plan::create(['code' => 'P206', 'name' => ['en' => 'G10 Gold'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 12, 'is_active' => true]);
        Bond::create(['member_id' => $this->member->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 50000, 'status' => 'active', 'invoice_no' => 'INV9', 'cbc_count' => 6, 'cbc_issued' => 2]);

        $res = $this->getJson('/api/v1/member/bonds')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'invoice_no', 'plan' => ['code', 'name', 'type'], 'value', 'status', 'cbc' => ['issued', 'count']]]]);
        $this->assertEquals('P206', $res->json('data.0.plan.code'));
        $this->assertEquals('G10 Gold', $res->json('data.0.plan.name'));
        $this->assertEquals(50000.0, $res->json('data.0.value'));
        $this->assertEquals(2, $res->json('data.0.cbc.issued'));
    }
}
