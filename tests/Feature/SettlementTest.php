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

    /**
     * Lord's board 2026-11-13 — Gold Saving never settles by itself: Head Office settles it on
     * the Settlements page once the saver has handed the coins back.
     *   all dues → gold QR with the bonus months at the FIRST due · coins not returned are
     *   deducted at the day's 100 mg price · a QR never redeemed is cancelled.
     */
    public function test_gold_saving_settles_by_hand_with_coins_back_and_bonus(): void
    {
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $coin = \App\Models\CatalogProduct::create(['code' => 'G100', 'name' => ['en' => '100 mg Gold'], 'material' => 'gold', 'default_weight' => 0.1, 'is_active' => true]);
        $contract = $this->setup_contract(
            ['type' => 'rd', 'validity_months' => 11, 'rd_qr_grams' => 0.1, 'rd_qr_on' => 'always', 'is_redeem' => true,
                'settlement_cycle_months' => 12, 'settlement_bonus_months' => 1, 'settlement_close' => true],
            1000, now()->subMonthsNoOverflow(13)->toDateString(), bondValue: 1000
        );
        $contract->update(['end_date' => now()->subDay()->toDateString()]);
        // 10 renewals at moving prices: 1,100 each → paid 1,000 + 11,000 = 12,000
        foreach (range(1, 10) as $i) {
            RdEntry::create(['bond_id' => $contract->bond_id, 'member_id' => $contract->member_id,
                'paid_on' => now()->subMonthsNoOverflow(12 - $i)->toDateString(), 'value' => 1100, 'due_count' => $i + 1, 'branch_id' => $this->branch->id]);
        }
        // 11 coins given: 8 redeemed (taken as coins), 3 QRs never redeemed
        foreach (range(1, 11) as $i) {
            RedeemableQr::create(['bond_id' => $contract->bond_id, 'member_id' => $contract->member_id, 'branch_id' => $this->branch->id,
                'qr_code' => 'GSQR' . $i, 'qr_mode' => 'gold', 'gram_worth' => 0.1, 'cash_worth' => 1000,
                'status' => $i <= 8 ? 'redeemed' : 'pending', 'qr_sent' => true]);
        }

        // the engine leaves it alone
        app(SettlementService::class)->run();
        $this->assertNull($contract->fresh()->settled_on);
        $this->assertEquals('active', $contract->fresh()->status);

        // saver hands back 6 of the 8 coins → 2 missing × ₹721 (100 mg @ ₹7,000 + 3 %) deducted
        $svc = app(\App\Services\Rd\GoldSavingSettlementService::class);
        $this->assertTrue($svc::canSettle($contract->fresh()));
        $row = $svc->settle($contract->fresh(), $this->branch->id, 6, null, null);

        $this->assertSame(11, (int) $row->dues_paid);
        $this->assertEquals(12000.0, (float) $row->paid_total);
        $this->assertEquals(1000.0, (float) $row->bonus);               // 1 month × the FIRST due
        $this->assertSame([11, 3, 6, 2], [(int) $row->coins_given, (int) $row->coins_cancelled, (int) $row->coins_returned, (int) $row->coins_missing]);
        $this->assertEquals(1442.0, (float) $row->deduction);
        $this->assertEquals(11558.0, (float) $row->worth);              // 12,000 + 1,000 − 1,442
        $this->assertSame('gold', $row->metal);

        $qr = $row->qr;
        $this->assertEquals(11558.0, (float) $qr->cash_worth);
        $this->assertSame('gold', $qr->qr_mode);
        $this->assertEquals(7000.0, (float) $qr->frozen_rate);          // redeemed at the settlement day's price
        $this->assertEquals(3, RedeemableQr::where('bond_id', $contract->bond_id)->where('status', 'cancelled')->count());
        $this->assertEquals(6.0, (float) \App\Models\Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $coin->id)->value('quantity'));
        $this->assertEquals('closed', $contract->fresh()->status);
        $this->assertEquals('closed', $contract->fresh()->bond->status);
        $this->assertFalse($svc::canSettle($contract->fresh()));       // once only
    }

    /** 6 to 10 dues → gold, no bonus · fewer than 6 → silver, no bonus (Lord's board 2026-11-13). */
    public function test_gold_saving_short_payers_get_no_bonus_and_silver_below_six_dues(): void
    {
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $make = function (int $renewals) {
            $c = $this->setup_contract(
                ['type' => 'rd', 'validity_months' => 11, 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 3, 'settlement_close' => true],
                1000, now()->subMonthsNoOverflow(13)->toDateString(), bondValue: 1000
            );
            $c->update(['end_date' => now()->subDay()->toDateString()]);
            foreach (range(1, $renewals) as $i) {
                RdEntry::create(['bond_id' => $c->bond_id, 'member_id' => $c->member_id, 'paid_on' => now()->subMonths(5)->toDateString(),
                    'value' => 1000, 'due_count' => $i + 1, 'branch_id' => $this->branch->id]);
            }

            return app(\App\Services\Rd\GoldSavingSettlementService::class)->settle($c->fresh(), $this->branch->id);
        };

        $seven = $make(6);                                              // joining + 6 = 7 dues
        $this->assertSame(['gold', 0.0, 7000.0], [$seven->metal, (float) $seven->bonus, (float) $seven->worth]);

        $four = $make(3);                                               // joining + 3 = 4 dues
        $this->assertSame(['silver', 0.0, 4000.0], [$four->metal, (float) $four->bonus, (float) $four->worth]);
        $this->assertSame('silver', $four->qr->qr_mode);
        $this->assertEquals(100.0, (float) $four->qr->frozen_rate);
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
