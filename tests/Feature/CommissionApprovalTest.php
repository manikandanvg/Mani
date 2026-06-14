<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\ResellerCommission;
use App\Services\CommissionApprovalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function member(string $code): Member
    {
        return Member::create([
            'member_code' => $code,
            'name' => $code,
            'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(),
            'placement' => 'level',
            'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active',
        ]);
    }

    /** IC approval credits the member's cash wallet, marks paid, and is idempotent. */
    public function test_ic_approval_credits_wallet_marks_paid_and_is_idempotent(): void
    {
        $m = $this->member('IC1');
        $row = CommissionLedger::create([
            'type' => 'IC', 'member_id' => $m->id, 'amount' => 250,
            'status' => 'pending', 'earned_on' => now()->toDateString(),
        ]);

        $svc = app(CommissionApprovalService::class);
        $this->assertTrue($svc->approve($row));

        // TDS 5% + service 5% withheld → net 225 credited; earning_total tracks gross 250.
        $wallet = $m->fresh()->wallet;
        $this->assertEquals(225, (float) $wallet->cash_balance);
        $this->assertEquals(250, (float) $wallet->earning_total);
        $row->refresh();
        $this->assertEquals('paid', $row->status);
        $this->assertNotNull($row->paid_on);
        $this->assertEquals(12.5, (float) $row->tds);
        $this->assertEquals(12.5, (float) $row->service_charge);
        $this->assertEquals(225, (float) $row->net_amount);

        // Second approval is a no-op (no double credit).
        $this->assertFalse($svc->approve($row));
        $this->assertEquals(225, (float) $m->fresh()->wallet->cash_balance);
    }

    /** A distributor margin credits the branch's mapped distributor member wallet. */
    public function test_reseller_margin_credits_distributor_member(): void
    {
        $branch = Branch::where('name', 'Trichy — Aishwaryam Jewellers')->firstOrFail();
        $distMember = $branch->distributorUser?->memberAccount;
        $this->assertNotNull($distMember, 'seeded distributor should map to a member');

        $row = ResellerCommission::create([
            'bill_date' => now()->toDateString(),
            'com_type_id' => 1,            // billing margin
            'branch_id' => $branch->id,
            'com_value' => 1200,
            'status' => 'passed',
        ]);

        $this->assertTrue(app(CommissionApprovalService::class)->approve($row));

        // 1200 gross − 5% TDS − 5% service = 1080 net to the distributor's wallet.
        $this->assertEquals(1080, (float) $distMember->fresh()->wallet->cash_balance);
        $this->assertEquals('paid', $row->fresh()->status);
        $this->assertEquals(60, (float) $row->fresh()->tds);
        $this->assertEquals(1080, (float) $row->fresh()->net_amount);
    }

    /** The query selects the right table/type and respects the date range. */
    public function test_query_filters_by_type_and_date(): void
    {
        $m = $this->member('Q1');
        CommissionLedger::create(['type' => 'IC', 'member_id' => $m->id, 'amount' => 10, 'status' => 'pending', 'earned_on' => '2026-06-05']);
        CommissionLedger::create(['type' => 'GAP', 'member_id' => $m->id, 'amount' => 20, 'status' => 'pending', 'earned_on' => '2026-06-05']);
        CommissionLedger::create(['type' => 'IC', 'member_id' => $m->id, 'amount' => 30, 'status' => 'pending', 'earned_on' => '2026-07-05']);
        CommissionLedger::create(['type' => 'IC', 'member_id' => $m->id, 'amount' => 40, 'status' => 'paid', 'earned_on' => '2026-06-05']);

        $svc = app(CommissionApprovalService::class);
        $ic = $svc->query('IC', '2026-06-01', '2026-06-30')->get();

        // Only the pending June IC row (10) — not GAP, not July, not already-paid.
        $this->assertCount(1, $ic);
        $this->assertEquals(10, (float) $ic->first()->amount);
    }
}
