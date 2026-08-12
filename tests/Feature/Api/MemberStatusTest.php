<?php

namespace Tests\Feature\Api;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /member/status — EP / TBP / PI cards + dashboard widget grid (board 2026-08-11). */
class MemberStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'ST1', 'name' => 'Status Holder', 'phone' => '9000000700',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
            'bv' => 50000, 'gbv' => 125000,
        ]);
        MemberWallet::create(['member_id' => $this->member->id, 'earning_total' => 4093.05, 'coupon_balance' => 250]);

        // downline for TBP
        Member::create([
            'member_code' => 'ST2', 'name' => 'Down One', 'phone' => '9000000701',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
            'upline_id' => $this->member->id, 'gbv' => 90000,
        ]);
    }

    public function test_status_reports_ep_tbp_pi_and_widgets(): void
    {
        $plan = \App\Models\Plan::create([
            'code' => 'PST', 'name' => ['en' => 'Status Plan'], 'plan_type' => 1, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true,
        ]);
        Bond::create([
            'member_id' => $this->member->id, 'plan_id' => $plan->id, 'value' => 50000, 'status' => 'active',
            'bond_date' => now()->toDateString(), 'invoice_no' => 'INV-HQ-0001',
        ]);
        CommissionLedger::create([
            'member_id' => $this->member->id, 'type' => 'IC', 'amount' => 174,
            'status' => 'pending', 'earned_on' => now()->toDateString(), 'pay_via' => 'digi_transfer',
        ]);

        Sanctum::actingAs($this->member, ['*']);

        $res = $this->getJson('/api/v1/member/status')->assertOk();

        $this->assertSame(1, $res->json('ep.active_contracts'));
        $this->assertSame(50000, $res->json('ep.active_contract_bv'));
        $this->assertSame(174, $res->json('ep.earned_today.ic'));
        $this->assertSame(49826, $res->json('ep.ep_estimate'));
        $this->assertSame('active', $res->json('ep.status'));

        $this->assertSame(1, $res->json('tbp.direct_downlines'));
        $this->assertSame('ST2', $res->json('tbp.top_distributors.0.member_code'));
        $this->assertSame(1, $res->json('tbp.levels_covered'));

        $this->assertSame(174, $res->json('pi.total_value'));
        $this->assertSame(0, $res->json('pi.installments.paid'));
        $this->assertSame(1, $res->json('pi.installments.total'));

        $this->assertSame(50000, $res->json('widgets.business'));
        $this->assertSame(125000, $res->json('widgets.gross_bv'));
        $this->assertSame(250, $res->json('widgets.gift'));
    }

    public function test_genealogy_returns_the_downline_tree(): void
    {
        // grandchild under ST2 to prove nesting
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $child = Member::where('member_code', 'ST2')->firstOrFail();
        Member::create([
            'member_code' => 'ST3', 'name' => 'Down Two', 'phone' => '9000000702',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
            'upline_id' => $child->id, 'gbv' => 1500,
        ]);

        Sanctum::actingAs($this->member, ['*']);

        $res = $this->getJson('/api/v1/member/genealogy')->assertOk();
        $this->assertSame('ST1', $res->json('root.member_code'));
        $this->assertSame('ST2', $res->json('root.children.0.member_code'));
        $this->assertSame('ST3', $res->json('root.children.0.children.0.member_code'));
    }

    public function test_status_is_member_only(): void
    {
        $customer = \App\Models\Customer::create(['phone' => '9000000800', 'name' => 'Shopper']);
        Sanctum::actingAs($customer, ['*']);

        $this->getJson('/api/v1/member/status')->assertStatus(403);
    }
}
