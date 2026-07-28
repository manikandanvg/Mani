<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Settlement engine — the board settlement sheet turned into behaviour (2026-07-28). */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Settle Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
    }

    protected function member(string $code): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => Rank::where('depth', 0)->value('id'),
        ]);
    }

    protected function setup_contract(array $planAttrs, float $amount, string $startDate, float $bondValue = null): MemberContract
    {
        $plan = Plan::create(array_merge([
            'code' => 'S' . random_int(100, 999) . chr(random_int(65, 90)),
            'name' => ['en' => 'Settle Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'is_contract' => true, 'is_active' => true,
        ], $planAttrs));
        $member = $this->member('SM' . random_int(1000, 9999));
        $bond = Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $this->branch->id,
            'bond_date' => $startDate, 'value' => $bondValue ?? $amount, 'invoice_no' => 'INV-' . $plan->code,
            'status' => 'active',
        ]);

        return MemberContract::create([
            'contract_no' => 'LJC-' . $plan->code, 'bond_id' => $bond->id, 'member_id' => $member->id,
            'plan_id' => $plan->id, 'branch_id' => $this->branch->id, 'invoice_no' => $bond->invoice_no,
            'amount' => $amount, 'start_date' => $startDate,
            'end_date' => Carbon::parse($startDate)->addMonthsNoOverflow(24)->toDateString(),
            'content' => '', 'status' => 'active',
        ]);
    }

    /** 70% settlement QR at month 12; the contract stays active (G5-style). */
    public function test_pct_plan_mints_settlement_qr_and_stays_active(): void
    {
        $contract = $this->setup_contract(
            ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 70],
            100000, now()->subMonthsNoOverflow(13)->toDateString()
        );

        $n = app(SettlementService::class)->run();

        $this->assertEquals(1, $n);
        $fresh = $contract->fresh();
        $this->assertEquals('active', $fresh->status);
        $this->assertNotNull($fresh->settled_on);

        $qr = RedeemableQr::where('bond_id', $contract->bond_id)->firstOrFail();
        $this->assertEquals(70000.0, (float) $qr->cash_worth);   // 70% of 1,00,000
        $this->assertEquals('pending', $qr->status);
    }

    /** Item 9: the settlement QR is based on the allocation_cont share of the contract amount. */
    public function test_pct_settlement_uses_allocation_cont_share(): void
    {
        $contract = $this->setup_contract(
            ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 80, 'allocation_cont' => 25],
            100000, now()->subMonthsNoOverflow(13)->toDateString()
        );

        app(SettlementService::class)->run();

        $qr = RedeemableQr::where('bond_id', $contract->bond_id)->firstOrFail();
        // 1,00,000 × 25% contract share × 80% = ₹20,000
        $this->assertEquals(20000.0, (float) $qr->cash_worth);
    }

    /** G11 RD (PLUS2-style): QR = paid total + 2 bonus months × monthly, then contract + bond close. */
    public function test_rd_plan_settles_paid_plus_bonus_and_closes(): void
    {
        $contract = $this->setup_contract(
            ['type' => 'rd', 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 2, 'settlement_close' => true],
            1000, now()->subMonthsNoOverflow(12)->toDateString(), bondValue: 1000
        );
        // 10 collections after the joining month → paid total 11 × 1000
        foreach (range(1, 10) as $i) {
            RdEntry::create([
                'bond_id' => $contract->bond_id, 'member_id' => $contract->member_id,
                'paid_on' => now()->subMonthsNoOverflow(12 - $i)->toDateString(),
                'value' => 1000, 'due_count' => $i + 1, 'branch_id' => $this->branch->id,
            ]);
        }

        app(SettlementService::class)->run();

        $qr = RedeemableQr::where('bond_id', $contract->bond_id)->firstOrFail();
        $this->assertEquals(13000.0, (float) $qr->cash_worth);   // 11 paid + 2 bonus months
        $this->assertEquals('closed', $contract->fresh()->status);
        $this->assertEquals('closed', $contract->fresh()->bond->status);
    }

    /** Area-Distributor-style: closes the contract + bond and suspends the dealer login. */
    public function test_close_suspend_plan_suspends_dealer_login(): void
    {
        $contract = $this->setup_contract(
            ['settlement_cycle_months' => 12, 'settlement_close' => true, 'settlement_suspend' => true],
            50000, now()->subMonthsNoOverflow(12)->toDateString()
        );
        $dealerBranch = Branch::create(['name' => 'AD Dealer', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        User::create([
            'name' => 'AD', 'email' => 'ad@lordicl', 'password' => bcrypt('x'), 'status' => 'active',
            'branch_id' => $dealerBranch->id, 'member_code' => $contract->member->member_code,
        ]);

        app(SettlementService::class)->run();

        $this->assertEquals('closed', $contract->fresh()->status);
        $this->assertDatabaseMissing('redeemable_qrs', ['bond_id' => $contract->bond_id]); // no pct → no QR
        $this->assertEquals('blocked', User::where('email', 'ad@lordicl')->value('status'));
    }

    /** Dealership-style: no pct, no close → 'matured' for the manual withdraw/renewal decision. */
    public function test_maturity_plan_goes_matured_without_qr(): void
    {
        $contract = $this->setup_contract(
            ['settlement_cycle_months' => 24],
            2500000, now()->subMonthsNoOverflow(25)->toDateString()
        );

        app(SettlementService::class)->run();

        $this->assertEquals('matured', $contract->fresh()->status);
        $this->assertEquals('active', $contract->fresh()->bond->status);   // bond untouched
        $this->assertDatabaseMissing('redeemable_qrs', ['bond_id' => $contract->bond_id]);
    }

    /** Not yet due, and plans with no cycle, never settle; a settled contract never settles twice. */
    public function test_not_due_no_cycle_and_idempotency(): void
    {
        $early = $this->setup_contract(['settlement_cycle_months' => 12, 'settlement_qr_pct' => 80],
            10000, now()->subMonthsNoOverflow(6)->toDateString());
        $never = $this->setup_contract([],
            10000, now()->subMonthsNoOverflow(30)->toDateString());
        $due = $this->setup_contract(['settlement_cycle_months' => 12, 'settlement_qr_pct' => 80],
            10000, now()->subMonthsNoOverflow(13)->toDateString());

        $svc = app(SettlementService::class);
        $this->assertEquals(1, $svc->run());     // only the due one
        $this->assertEquals(0, $svc->run());     // second run settles nothing

        $this->assertNull($early->fresh()->settled_on);
        $this->assertNull($never->fresh()->settled_on);
        $this->assertEquals(1, RedeemableQr::where('bond_id', $due->bond_id)->count());
    }
}
