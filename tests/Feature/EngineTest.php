<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Services\CommissionService;
use App\Services\NetworkService;
use App\Services\PurchaseService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class); // ranks 0..4, currencies, languages
    }

    protected function rankByDepth(int $depth): Rank
    {
        return Rank::where('depth', $depth)->firstOrFail();
    }

    protected function member(string $code, ?int $uplineId, int $depth = 0): Member
    {
        $m = Member::create([
            'member_code' => $code,
            'name' => $code,
            'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(),
            'upline_id' => $uplineId,
            'placement' => 'level',
            'rank_id' => $this->rankByDepth($depth)->id,
            'status' => 'active',
        ]);
        MemberWallet::create(['member_id' => $m->id]);

        return $m;
    }

    protected function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'P' . random_int(1000, 9999),
            'name' => ['en' => 'Test Plan'],
            'type' => 'rd',
            'min_value' => 1000,
            'allocation_bv' => 100,
            'validity_months' => 11,
            'cbc_value' => 0,
            'cbc_count' => 0,
            'ic_schedule' => ['10', '5', '2'],
            'level_schedule' => ['2.5', '1', '0.5', '0.5', '0.5'],
            'epin_count' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function bond(Member $m, Plan $plan, array $overrides = []): Bond
    {
        return Bond::create(array_merge([
            'member_id' => $m->id,
            'plan_id' => $plan->id,
            'bond_date' => now(),
            'value' => 1000,
            'lvlcom_count' => 11,
            'lvlcom_issued' => 0,
            'cbc_value' => 0,
            'cbc_count' => 0,
            'epin_value' => 0,
            'status' => 'active',
        ], $overrides));
    }

    public function test_bv_and_gbv_recompute(): void
    {
        $plan = $this->plan(['allocation_bv' => 100]);
        $m1 = $this->member('M1', null);
        $m2 = $this->member('M2', $m1->id);
        $m3 = $this->member('M3', $m2->id);
        $this->bond($m1, $plan, ['value' => 1000]);
        $this->bond($m2, $plan, ['value' => 2000]);
        $this->bond($m3, $plan, ['value' => 3000]);

        app(NetworkService::class)->recomputeAll();

        $this->assertEquals(1000, $m1->fresh()->bv);
        $this->assertEquals(2000, $m2->fresh()->bv);
        $this->assertEquals(5000, $m1->fresh()->gbv);   // 2000 + 3000
        $this->assertEquals(2, $m1->fresh()->downline_count);
        $this->assertEquals(3000, $m2->fresh()->gbv);
        $this->assertEquals(0, $m3->fresh()->gbv);
    }

    public function test_unpure_bv_uses_allocation_factor(): void
    {
        // Bond 1,000 at 50% BV allocation = a ₹2,000 bill; rank 50% of the BILL → 1,000.
        $plan = $this->plan(['allocation_bv' => 50, 'rank_factor' => 50]);
        $m = $this->member('M1', null);
        $this->bond($m, $plan, ['value' => 1000]);

        app(NetworkService::class)->recomputeUnpureBv();

        $this->assertEquals(1000, $m->fresh()->unpure_bv);
    }

    public function test_instant_commission_pays_uplines(): void
    {
        $plan = $this->plan(['ic_schedule' => ['10', '5', '2']]);
        $m1 = $this->member('M1', null);
        $m2 = $this->member('M2', $m1->id);
        $m3 = $this->member('M3', $m2->id);
        $m4 = $this->member('M4', $m3->id);

        // EP gate (board 2026-08-11, legacy checkmemberactive): an upline earns IC
        // only while holding ≥1 active bond — give each one earning headroom.
        $this->bond($m1, $plan, ['lvlcom_count' => 0]);
        $this->bond($m2, $plan, ['lvlcom_count' => 0]);
        $this->bond($m3, $plan, ['lvlcom_count' => 0]);

        $bond = $this->bond($m4, $plan, ['value' => 1000]);
        $count = app(CommissionService::class)->issueInstantCommission($bond);

        $this->assertEquals(3, $count);
        $this->assertEquals(100, CommissionLedger::where('member_id', $m3->id)->where('type', 'IC')->sum('amount')); // 10%
        $this->assertEquals(50, CommissionLedger::where('member_id', $m2->id)->where('type', 'IC')->sum('amount'));  // 5%
        $this->assertEquals(20, CommissionLedger::where('member_id', $m1->id)->where('type', 'IC')->sum('amount'));  // 2%

        // IC is recorded PENDING — the wallet is not credited until admin approval.
        $this->assertEquals(0, $m3->fresh()->wallet->cash_balance);
        $this->assertEquals('pending', CommissionLedger::where('member_id', $m3->id)->where('type', 'IC')->value('status'));
    }

    public function test_instant_commission_respects_earning_potential(): void
    {
        $plan = $this->plan(['ic_schedule' => ['10', '5', '2']]);
        $m1 = $this->member('M1', null);                       // NO bond → EP 0, earns nothing
        $m2 = $this->member('M2', $m1->id);                    // bond of 30 → 5% (50) capped to 30
        $m3 = $this->member('M3', $m2->id);                    // bond of 1000 → full 10%
        $m4 = $this->member('M4', $m3->id);
        $this->bond($m2, $plan, ['value' => 30, 'lvlcom_count' => 0]);
        $this->bond($m3, $plan, ['value' => 1000, 'lvlcom_count' => 0]);

        $bond = $this->bond($m4, $plan, ['value' => 1000]);
        $count = app(CommissionService::class)->issueInstantCommission($bond);

        $this->assertEquals(2, $count);   // bond-less M1 is skipped entirely
        $this->assertEquals(100, CommissionLedger::where('member_id', $m3->id)->where('type', 'IC')->sum('amount'));
        $this->assertEquals(30, CommissionLedger::where('member_id', $m2->id)->where('type', 'IC')->sum('amount'));
        $this->assertEquals(0, CommissionLedger::where('member_id', $m1->id)->count());
    }

    public function test_gap_respects_rank_qualification(): void
    {
        $plan = $this->plan(['level_schedule' => ['2.5', '1', '0.5']]);
        // uplines with rank depths: M3 depth1 (qualifies L1), M2 depth2 (L2), M1 depth0 (NOT L3)
        $m1 = $this->member('M1', null, depth: 0);
        $m2 = $this->member('M2', $m1->id, depth: 2);
        $m3 = $this->member('M3', $m2->id, depth: 1);
        $m4 = $this->member('M4', $m3->id, depth: 0);

        // GAP is EP-gated like IC — qualifying uplines need active bonds. lvlcom_count 0
        // keeps these bonds from emitting GAP instalments of their own in this run.
        $this->bond($m2, $plan, ['lvlcom_count' => 0]);
        $this->bond($m3, $plan, ['lvlcom_count' => 0]);

        $bond = $this->bond($m4, $plan, ['value' => 1000, 'lvlcom_count' => 11]);
        $issued = app(CommissionService::class)->runGap(Carbon::now());

        $this->assertEquals(25, CommissionLedger::where('member_id', $m3->id)->where('type', 'GAP')->sum('amount')); // L1 2.5%, depth1 ok
        $this->assertEquals(10, CommissionLedger::where('member_id', $m2->id)->where('type', 'GAP')->sum('amount')); // L2 1%, depth2 ok
        $this->assertEquals(0, CommissionLedger::where('member_id', $m1->id)->where('type', 'GAP')->count());        // L3 needs depth3, M1 depth0
        $this->assertEquals(1, $bond->fresh()->lvlcom_issued);
        $this->assertGreaterThanOrEqual(2, $issued);
    }

    public function test_cbc_issued_pending_then_credits_on_approval(): void
    {
        $plan = $this->plan();
        $m = $this->member('M1', null);
        $bond = $this->bond($m, $plan, ['cbc_value' => 100, 'cbc_count' => 3, 'cbc_issued' => 0]);

        app(CommissionService::class)->issueCbc(Carbon::now());

        // Issued PENDING in cbc_entries — no wallet credit yet.
        $entry = \App\Models\CbcEntry::where('member_id', $m->id)->firstOrFail();
        $this->assertEquals('pending', $entry->status);
        $this->assertEquals(0, $m->fresh()->wallet->epin_balance);
        $this->assertEquals(1, $bond->fresh()->cbc_issued);

        // Admin approval posts the 40% EPIN / 60% coupon split.
        app(\App\Services\CommissionApprovalService::class)->approve($entry);

        $wallet = $m->fresh()->wallet;
        $this->assertEquals(40, $wallet->epin_balance);    // 40%
        $this->assertEquals(60, $wallet->coupon_balance);  // 60%
        $this->assertEquals('paid', $entry->fresh()->status);
    }

    public function test_payout_statement_applies_tds_and_service(): void
    {
        $plan = $this->plan();
        $m1 = $this->member('M1', null);
        $m2 = $this->member('M2', $m1->id);
        $this->bond($m1, $plan, ['lvlcom_count' => 0]);   // EP headroom for the earner
        $bond = $this->bond($m2, $plan, ['value' => 1000]);
        app(CommissionService::class)->issueInstantCommission($bond); // M1 gets 10% = 100 (pending)

        $period = now()->format('Y-m');
        app(CommissionService::class)->generatePayoutStatements($period);

        $this->assertDatabaseHas('payout_statements', [
            'member_id' => $m1->id,
            'period' => $period,
            'net_total' => 100.00,
            'tds' => 5.00,           // 5%
            'service_charge' => 5.00, // 5%
            'grand_total' => 90.00,
        ]);
    }

    public function test_register_purchase_creates_bond_and_pays_instant_commission(): void
    {
        $plan = $this->plan(['ic_schedule' => ['10', '5']]);
        $upline = $this->member('UP1', null);
        $this->bond($upline, $plan, ['lvlcom_count' => 0]);   // EP headroom for the earner

        $bond = app(PurchaseService::class)->registerPurchase([
            'plan_id' => $plan->id,
            'value' => 1000,
            'new_member' => [
                'name' => 'Walk-in Buyer',
                'phone' => '9123456780',
                'upline_id' => $upline->id,
                'rank_id' => $this->rankByDepth(0)->id,
            ],
        ]);

        $this->assertNotNull($bond->id);
        $this->assertEquals('active', $bond->status);
        $this->assertDatabaseHas('members', ['name' => 'Walk-in Buyer']);
        // upline gets 10% instant commission on the 1000 purchase
        $this->assertEquals(100, CommissionLedger::where('member_id', $upline->id)->where('type', 'IC')->sum('amount'));
        // network recompute ran: buyer's bond counts toward upline GBV
        $this->assertEquals(1000, $upline->fresh()->gbv);
    }

    public function test_money_formats_and_converts_against_base(): void
    {
        // DatabaseSeeder seeds INR (base) + USD with a rate_to_base.
        $this->assertStringContainsString('1,000', Money::format(1000));   // base currency

        $usd = \App\Models\Currency::where('code', 'USD')->first();
        if ($usd) {
            $expected = round(1000 * (float) $usd->rate_to_base, $usd->decimals);
            $this->assertEquals($expected, Money::convert(1000, 'USD'));
        }
    }
}
