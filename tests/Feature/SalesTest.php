<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\Stock;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class); // HQ branch id=1, ranks, etc.

        $this->plan = Plan::create([
            'code' => 'G10', 'name' => ['en' => 'Gold 10'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 500, 'allocation_pct' => 100, 'validity_months' => 11,
            'cbc_value' => 10, 'cbc_count' => 11,
            'ic_schedule' => ['10', '5', '2'], 'level_schedule' => ['2.5', '1'],
            'level_depth' => 2, 'level_com_duration' => 11,
            'billing_margin' => 2, 'gm_margin' => 0, 'stock_trans_margin' => 0,
            'is_active' => true,
        ]);
    }

    protected function member(string $code, ?int $uplineId): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'upline_id' => $uplineId, 'placement' => 'level',
            'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active',
        ]);
    }

    protected function bond(Member $m, float $value): Bond
    {
        return Bond::create([
            'member_id' => $m->id, 'plan_id' => $this->plan->id, 'bond_date' => now(),
            'value' => $value, 'cbc_count' => 0, 'lvlcom_count' => 11, 'status' => 'active',
        ]);
    }

    public function test_generate_invoice_full_flow(): void
    {
        $branch = Branch::create(['name' => 'Shop A', 'country' => 'IN', 'is_active' => true]); // non-HQ
        $u1 = $this->member('U1', null);
        $u2 = $this->member('U2', $u1->id);
        $u3 = $this->member('U3', $u2->id);
        foreach ([$u1, $u2, $u3] as $u) {
            $this->bond($u, 100000); // big headroom so the cap doesn't bind
        }

        $cp = CatalogProduct::create(['code' => 'G2', 'name' => ['en' => '2g Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 50]);

        $invoice = app(SalesService::class)->generateInvoice([
            'branch_id' => $branch->id,
            'plan_id' => $this->plan->id,
            'upline_code' => 'U3', 'referrer_code' => 'U3',
            'gold_rate' => 7000,
            'customer' => ['name' => 'New Cust', 'phone' => '9000000001', 'pan' => 'ABCDE1234F'],
            'cart' => [[
                'catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 10,
                'net_total' => 1000, 'grand_total' => 1030,
            ]],
        ]);

        // invoice totals
        $this->assertEquals(1000, $invoice->cross_total);
        $this->assertEquals(1030, $invoice->grand_total);
        $this->assertEquals(15, $invoice->sgst);
        $this->assertEquals(15, $invoice->cgst);

        // new member placed under U3
        $member = Member::where('name', 'New Cust')->firstOrFail();
        $this->assertEquals($u3->id, $member->upline_id);

        // bond = allocated amount (100% of cross)
        $bond = Bond::where('member_id', $member->id)->firstOrFail();
        $this->assertEquals(1000, $bond->value);

        // CBC schedule = cbc_count rows
        $this->assertEquals(11, CbcEntry::where('bond_id', $bond->id)->count());

        // IC across levels (pending), capped by headroom (not binding here)
        $this->assertEquals(100, CommissionLedger::where('member_id', $u3->id)->where('type', 'IC')->sum('amount'));
        $this->assertEquals(50, CommissionLedger::where('member_id', $u2->id)->where('type', 'IC')->sum('amount'));
        $this->assertEquals(20, CommissionLedger::where('member_id', $u1->id)->where('type', 'IC')->sum('amount'));
        $this->assertEquals('pending', CommissionLedger::where('member_id', $u3->id)->first()->status);

        // GBV roll-up uses the allocated amount
        $this->assertEquals(1000, $u3->fresh()->gbv);
        $this->assertEquals(1000, $u2->fresh()->gbv);
        $this->assertEquals(1000, $u1->fresh()->gbv);
        $this->assertEquals(1, $u3->fresh()->downline_count);

        // stock deducted
        $this->assertEquals(40, Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));

        // bill margin (2% of cross) to the non-HQ branch
        $this->assertDatabaseHas('reseller_commissions', ['invoice_no' => $invoice->invoice_no, 'com_value' => 20.00]);
        $this->assertEquals(20, $branch->fresh()->bill_margin);

        // redeemable stock QR minted for the bond (worth = grand total; grams via gold rate)
        $qr = \App\Models\RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $this->assertEquals(1030, (float) $qr->cash_worth);
        $this->assertEqualsWithDelta(1030 / 7000, (float) $qr->gram_worth, 0.0001);
        $this->assertFalse((bool) $qr->qr_sent); // delivery is skipped under tests
    }

    /** GM margin: gold lines credit gold_gm_margin, silver lines silver_gm_margin, instantly. */
    public function test_gold_and_silver_gm_margin_settle_to_billing_branch(): void
    {
        $branch = Branch::create(['name' => 'GM Shop', 'country' => 'IN', 'is_active' => true]); // non-HQ
        $cust = $this->member('GMC', null);

        $gold = CatalogProduct::create(['code' => 'GM-G', 'name' => ['en' => '1g Gold'], 'material' => 'gold', 'gm_margin' => 100, 'gst_pct' => 3, 'is_active' => true]);
        $silver = CatalogProduct::create(['code' => 'GM-S', 'name' => ['en' => '10g Silver'], 'material' => 'silver', 'gm_margin' => 20, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 100]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $silver->id, 'quantity' => 100]);

        $invoice = app(SalesService::class)->generateInvoice([
            'branch_id' => $branch->id, 'plan_id' => $this->plan->id,
            'customer' => ['member_id' => $cust->id],
            'cart' => [
                ['catalog_product_id' => $gold->id, 'material' => 'gold', 'weight' => 5, 'net_total' => 1000, 'grand_total' => 1030],
                ['catalog_product_id' => $silver->id, 'material' => 'silver', 'weight' => 10, 'net_total' => 500, 'grand_total' => 515],
            ],
        ]);

        $branch->refresh();
        $this->assertEquals(500.0, (float) $branch->gold_gm_margin);    // 5g × ₹100
        $this->assertEquals(200.0, (float) $branch->silver_gm_margin);  // 10g × ₹20

        $this->assertDatabaseHas('reseller_commissions', ['invoice_no' => $invoice->invoice_no, 'com_type_id' => 2, 'com_value' => 500.00]);
        $this->assertDatabaseHas('reseller_commissions', ['invoice_no' => $invoice->invoice_no, 'com_type_id' => 3, 'com_value' => 200.00]);
    }

    /** HQ bills earn no GM margin (head office is not a reseller). */
    public function test_hq_bill_earns_no_gm_margin(): void
    {
        $hq = Branch::find(SalesService::HQ_BRANCH_ID);
        $cust = $this->member('GMHQ', null);
        $gold = CatalogProduct::create(['code' => 'GM-HQ', 'name' => ['en' => '1g Gold'], 'material' => 'gold', 'gm_margin' => 100, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $hq->id, 'catalog_product_id' => $gold->id, 'quantity' => 100]);

        app(SalesService::class)->generateInvoice([
            'branch_id' => $hq->id, 'plan_id' => $this->plan->id,
            'customer' => ['member_id' => $cust->id],
            'cart' => [['catalog_product_id' => $gold->id, 'material' => 'gold', 'weight' => 5, 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        $this->assertEquals(0.0, (float) $hq->fresh()->gold_gm_margin);
    }

    /** Plan with clean CBC numbers: monthly = 1000 * 30% / 10 = 30.00, per-day = 1.00. */
    protected function cbcPlan(): Plan
    {
        return Plan::create([
            'code' => 'CBC', 'name' => ['en' => 'Cbc Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_pct' => 100, 'validity_months' => 10,
            'cbc_value' => 30, 'cbc_count' => 10,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 10,
            'billing_margin' => 0, 'is_active' => true,
        ]);
    }

    /**
     * CBC proration parity with legacy Trade.php: first coupon is dated the 1st of next
     * month, prorated by days(bill → 1st-of-next-month) over /30; the rest are full months.
     */
    public function test_cbc_proration_matches_legacy(): void
    {
        $plan = $this->cbcPlan();
        $customer = $this->member('CB1', null);

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $plan->id,
            'bill_date' => '2026-06-08',           // 23 days to 1 Jul
            'customer' => ['member_id' => $customer->id],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        $bond = Bond::where('member_id', $customer->id)->firstOrFail();
        $rows = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->get();

        $this->assertCount(10, $rows);                              // total = cbc_count
        // first coupon: dated 1 Jul, worth = 30/30 * 23 = 23.00 (prorated)
        $this->assertEquals('2026-07-01', $rows->first()->cbc_date->toDateString());
        $this->assertEquals(23.00, (float) $rows->first()->worth);
        // remaining nine: full 30.00, on the 1st of each following month
        $this->assertEquals('2026-08-01', $rows[1]->cbc_date->toDateString());
        $this->assertEquals(30.00, (float) $rows[1]->worth);
        $this->assertEquals(23.00 + 9 * 30.00, (float) $rows->sum('worth'));
    }

    /** Edge: a 1st-of-month sale gives a 31-day gap, which legacy caps to a full 30-day month. */
    public function test_cbc_first_month_caps_at_thirty_days(): void
    {
        $plan = $this->cbcPlan();
        $customer = $this->member('CB2', null);

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $plan->id,
            'bill_date' => '2026-01-01',           // 31 days to 1 Feb -> capped to 30
            'customer' => ['member_id' => $customer->id],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        $bond = Bond::where('member_id', $customer->id)->firstOrFail();
        $first = CbcEntry::where('bond_id', $bond->id)->orderBy('cbc_date')->first();
        $this->assertEquals('2026-02-01', $first->cbc_date->toDateString());
        $this->assertEquals(30.00, (float) $first->worth);   // 30/30 * 30, not * 31
    }

    /**
     * Full 10-level IC flow, parity with legacy tbl_iccom (lordsalessave 1772-1861):
     * one PENDING row per upline level, beneficiary = that upline, amount = allocation x
     * the plan's per-level iccom %, capped by each upline's earning headroom.
     */
    public function test_ic_ten_level_flow_matches_legacy(): void
    {
        $branch = Branch::create(['name' => 'Shop B', 'country' => 'IN', 'is_active' => true]);

        $plan = Plan::create([
            'code' => 'IC10', 'name' => ['en' => 'IC Ten'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_pct' => 100, 'validity_months' => 12,
            'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => ['10', '3', '2', '1.5', '0.75', '0.75', '0.5', '0.5', '0.5', '0.5'],
            'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 12,
            'billing_margin' => 0, 'is_active' => true,
        ]);

        // Build a 10-deep upline chain: L1 = immediate upline … L10 = top. Big bonds so the cap never binds.
        $byLevel = [];
        $prev = null;
        for ($lvl = 10; $lvl >= 1; $lvl--) {
            $m = $this->member("L{$lvl}", $prev?->id);
            $this->bond($m, 1_000_000);
            $byLevel[$lvl] = $m;
            $prev = $m;                              // next (lower) level's upline
        }

        app(SalesService::class)->generateInvoice([
            'branch_id' => $branch->id,
            'plan_id' => $plan->id,
            'upline_code' => 'L1', 'referrer_code' => 'L1',     // new member sits under L1
            'customer' => ['name' => 'IC Buyer', 'phone' => '9000000002'],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 100000, 'grand_total' => 103000]],
        ]);

        $buyer = Member::where('name', 'IC Buyer')->firstOrFail();
        $bond = Bond::where('member_id', $buyer->id)->firstOrFail();

        // Exactly 10 IC rows — one per level.
        $ic = CommissionLedger::where('type', 'IC')->where('from_member_id', $buyer->id)->get();
        $this->assertCount(10, $ic);

        $expected = [1 => 10000, 2 => 3000, 3 => 2000, 4 => 1500, 5 => 750, 6 => 750, 7 => 500, 8 => 500, 9 => 500, 10 => 500];
        foreach ($expected as $level => $amount) {
            $row = $ic->firstWhere('level', $level);
            $this->assertNotNull($row, "missing IC level {$level}");
            $this->assertEquals($byLevel[$level]->id, $row->member_id, "level {$level} beneficiary");
            $this->assertEquals($amount, (float) $row->amount, "level {$level} amount");
            $this->assertEquals('pending', $row->status);
            $this->assertEquals($bond->id, $row->bond_id);
        }
        $this->assertEquals(array_sum($expected), (float) $ic->sum('amount'));   // 20000 total
    }

    /** Canonical IC plan — ALWAYS a 10-level schedule; payout count is driven by chain depth. */
    protected function icPlan(): Plan
    {
        return Plan::create([
            'code' => 'IC', 'name' => ['en' => 'IC Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_pct' => 100, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => ['10', '3', '2', '1.5', '0.75', '0.75', '0.5', '0.5', '0.5', '0.5'],
            'level_schedule' => [], 'billing_margin' => 0, 'is_active' => true,
        ]);
    }

    /** Build an upline chain of $depth members; returns [1 => immediate upline … depth => top]. */
    protected function buildChain(int $depth, string $tag): array
    {
        $byLevel = [];
        $prev = null;
        for ($lvl = $depth; $lvl >= 1; $lvl--) {
            $m = $this->member("{$tag}{$lvl}", $prev?->id);
            $this->bond($m, 1_000_000);                 // big headroom so the cap never binds
            $byLevel[$lvl] = $m;
            $prev = $m;
        }

        return $byLevel;
    }

    /**
     * Short chain: a member with only 4 uplines above (placed at genealogy level 5) gets
     * IC paid to just those 4 — the missing levels 5-10 are simply skipped, not compressed.
     */
    public function test_ic_pays_only_available_uplines_for_short_chain(): void
    {
        $plan = $this->icPlan();
        $chain = $this->buildChain(4, 'S');            // 4 uplines only

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $plan->id,
            'upline_code' => 'S1', 'referrer_code' => 'S1',
            'customer' => ['name' => 'Short Buyer', 'phone' => '9000000003'],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 100000, 'grand_total' => 103000]],
        ]);

        $buyer = Member::where('name', 'Short Buyer')->firstOrFail();
        $ic = CommissionLedger::where('type', 'IC')->where('from_member_id', $buyer->id)->get();

        $this->assertCount(4, $ic);                                   // only the 4 that exist
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $ic->pluck('level')->all());
        $this->assertEquals(10000 + 3000 + 2000 + 1500, (float) $ic->sum('amount'));
        // each paid to the correct upline
        foreach ([1 => 10000, 2 => 3000, 3 => 2000, 4 => 1500] as $level => $amount) {
            $row = $ic->firstWhere('level', $level);
            $this->assertEquals($chain[$level]->id, $row->member_id);
            $this->assertEquals($amount, (float) $row->amount);
        }
    }

    /**
     * Deep chain: a member with 13 uplines above (placed at genealogy level 14) gets IC
     * paid to the TOP 10 only — capped at 10 levels; uplines beyond level 10 get nothing.
     */
    public function test_ic_caps_at_ten_levels_for_deep_chain(): void
    {
        $plan = $this->icPlan();
        $chain = $this->buildChain(13, 'D');           // 13 uplines available

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $plan->id,
            'upline_code' => 'D1', 'referrer_code' => 'D1',
            'customer' => ['name' => 'Deep Buyer', 'phone' => '9000000004'],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 100000, 'grand_total' => 103000]],
        ]);

        $buyer = Member::where('name', 'Deep Buyer')->firstOrFail();
        $ic = CommissionLedger::where('type', 'IC')->where('from_member_id', $buyer->id)->get();

        $this->assertCount(10, $ic);                                  // capped at 10
        $this->assertEquals(10, $ic->max('level'));
        // uplines at genealogy distance 11, 12, 13 receive no IC
        foreach ([11, 12, 13] as $level) {
            $this->assertNull(CommissionLedger::where('type', 'IC')->where('member_id', $chain[$level]->id)->first());
        }
    }

    /** Ranks update at the moment of billing (incremental) — no recompute is run. */
    public function test_rank_updates_at_billing_without_recompute(): void
    {
        $plan = Plan::create([
            'code' => 'RKG', 'name' => ['en' => 'Rank Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_pct' => 100, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => [], 'level_schedule' => [], 'billing_margin' => 0, 'is_active' => true,
        ]);
        $a = $this->member('RA', null);
        $b = $this->member('RB', $a->id);

        $svc = app(SalesService::class);
        $bill = fn (Member $m) => $svc->generateInvoice([
            'branch_id' => 1, 'plan_id' => $plan->id,
            'customer' => ['member_id' => $m->id],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 50000, 'grand_total' => 51500]],
        ]);

        $bill($a);   // A: self unpure 50k, but no qualifying downline yet
        $this->assertEquals('MEMBER', $a->fresh()->rank->code);

        $bill($b);   // B: self 50k → A now has a 50k direct leg → A promotes, live
        $this->assertEquals('TALUK_DIRECTOR', $a->fresh()->rank->code);
        $this->assertEquals(50000, (float) $a->fresh()->unpure_bv);
        $this->assertEquals(50000, (float) $a->fresh()->unpure_gbv);   // B's unpure rolled up
    }

    public function test_instant_commission_respects_earning_cap(): void
    {
        $u1 = $this->member('C1', null);
        $this->bond($u1, 30);          // headroom only 30
        $customer = $this->member('C2', $u1->id);

        app(SalesService::class)->generateInvoice([
            'branch_id' => 1,
            'plan_id' => $this->plan->id,
            'upline_code' => 'C1', 'referrer_code' => 'C1',
            'customer' => ['member_id' => $customer->id],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        // IC would be 10% of 1000 = 100, but capped to the bonded headroom of 30
        $this->assertEquals(30, CommissionLedger::where('member_id', $u1->id)->where('type', 'IC')->sum('amount'));
    }
}
