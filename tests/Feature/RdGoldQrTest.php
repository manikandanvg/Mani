<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Services\RdCollectionService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G11 RD gold-QR rules (board spec 2026-07-28): 100 mg-gold-backed QRs and fixed
 * renewal amounts for PLUS1/PLUS2, RD collection ceilings, contract refresh on renewal,
 * and allocation_cont-based sale-QR worth.
 */
class RdGoldQrTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'RD Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        // gold ₹7,000/g → 100 mg = ₹700 (+3% GST default = ₹721)
        LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
    }

    protected function member(string $code): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => Rank::where('depth', 0)->value('id'),
        ]);
    }

    protected function rdPlan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'RQ' . random_int(100, 999) . chr(random_int(65, 90)),
            'name' => ['en' => 'RD QR Plan'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 0, 'allocation_cont' => 100,
            'validity_months' => 11, 'is_redeem' => true, 'is_contract' => true, 'is_active' => true,
        ], $attrs));
    }

    protected function bond(Plan $plan, Member $m, float $value): Bond
    {
        return Bond::create([
            'member_id' => $m->id, 'plan_id' => $plan->id, 'branch_id' => $this->branch->id,
            'bond_date' => now()->toDateString(), 'value' => $value, 'invoice_no' => 'INV-' . $plan->code,
            'status' => 'active',
        ]);
    }

    /** PLUS1-style ('always'): every renewal mints a 100 mg QR and the amount is fixed server-side. */
    public function test_always_plan_fixed_amount_and_qr_each_renewal(): void
    {
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always']);
        $bond = $this->bond($plan, $this->member('RQ1'), 721);

        // Branch passes a WRONG amount — the service overrides with the 100 mg price.
        $entry = app(RdCollectionService::class)->collect([
            'bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 999999,
        ]);
        $this->assertEquals(721.0, (float) $entry->value);   // 0.1g × 7000 = 700 + 3% GST

        $qrs = RedeemableQr::where('bond_id', $bond->id)->get();
        $this->assertCount(1, $qrs);
        $this->assertEquals(721.0, (float) $qrs->first()->cash_worth);

        // Second renewal → second QR.
        app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $this->assertEquals(2, RedeemableQr::where('bond_id', $bond->id)->count());
    }

    /**
     * User 2026-11-05: the 100 mg price takes making + wastage from Charge Brackets (the plan
     * has no "QR product") — it had carried the 3 % GST alone. Legacy 31 Oct 2026, gold ₹12,730:
     * ₹1,273 + 20 % + 20 % = ₹1,782.20, + 3 % GST = ₹1,835.67.
     */
    public function test_fixed_amount_and_qr_worth_carry_the_charge_bracket_making_and_wastage(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 12730, 'silver' => 200, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()->addMinute()]);
        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 0.001, 'wt_to' => 0.1, 'making_pct' => 20, 'wastage_pct' => 20]);
        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 0.101, 'wt_to' => 0.3, 'making_pct' => 15, 'wastage_pct' => 15]);
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always']);

        $b = app(\App\Services\Qr\GoldQrPricing::class)->breakup($plan);
        $this->assertEquals([1273.0, 254.6, 254.6, 53.47, 1835.67], [$b['metal'], $b['making'], $b['wastage'], $b['gst'], $b['total']]);

        $bond = $this->bond($plan, $this->member('RQ9'), 1836);
        $entry = app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1311.19]);
        $this->assertEquals(1835.67, (float) $entry->value);

        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $this->assertEquals(1835.67, (float) $qr->cash_worth);
        $this->assertEquals(0.1, (float) $qr->gram_worth);
    }

    /**
     * User 2026-11-06 (legacy admin/trade/fetchrdsalesdetails, plan 210): a PLUS1 renewal is
     * intdiv(bond value, ₹1,500) × the 100 mg price — a ₹1,00,000 saver renews 66 units, not one.
     */
    public function test_plus1_renewal_scales_with_the_bond_value_like_legacy(): void
    {
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always']);
        $bond = $this->bond($plan, $this->member('RQ6'), 100000);

        $entry = app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 721]);
        $this->assertEquals(66 * 721.0, (float) $entry->value);           // 47,586

        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();   // the QR backs all 66 pieces
        $this->assertEquals(47586.0, (float) $qr->cash_worth);
        $this->assertEquals(6.6, (float) $qr->gram_worth);
        $this->assertEquals(1, RdCollectionService::duesCovered($bond));
    }

    /**
     * Lord's board 2026-11-13 — PLUS 2: month 2 collects pieces (joining ÷ ₹1,500) × that day's
     * 100 mg price and gives the gold QR for those pieces; every other month is the joining amount.
     */
    public function test_plus2_month_two_collects_the_gold_price_then_the_joining_amount(): void
    {
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'first_renewal', 'rd_step' => 1500, 'min_value' => 1500]);
        $bond = $this->bond($plan, $this->member('RQ7'), 3000);
        $svc = app(RdCollectionService::class);

        $second = $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $this->assertEquals(2 * 721.0, (float) $second->value);          // 2 pieces × ₹721, not ₹3,000
        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $this->assertEquals(1442.0, (float) $qr->cash_worth);
        $this->assertEquals(0.2, (float) $qr->gram_worth);
        $this->assertEquals(7000.0, (float) $qr->frozen_rate);           // redeemed at that day's price

        $third = $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 99999]);
        $this->assertEquals(3000.0, (float) $third->value);              // the joining amount, whatever was typed
        $this->assertEquals(1, RedeemableQr::where('bond_id', $bond->id)->count());
    }

    /**
     * Lord's board 2026-11-13 — joining amounts: PLUS 1 in whole 100 mg pieces at today's price
     * (pieces kept for the scheme), PLUS 2 in ₹1,500 multiples, PLUS 3 from ₹1,000 in ₹500 steps.
     */
    public function test_joining_amounts_follow_the_board_rules(): void
    {
        $bill = fn (Plan $plan, float $amount, string $phone) => app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $plan->id, 'gold_rate' => 7000,
            'customer' => ['name' => 'GS ' . $phone, 'phone' => $phone],
            'cart' => [['material' => 'cash', 'net_total' => $amount, 'grand_total' => $amount]],
        ]);
        $refused = function (Plan $plan, float $amount, string $phone) use ($bill) {
            try {
                $bill($plan, $amount, $phone);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                return $e->getMessage();
            }
            $this->fail("₹{$amount} should have been refused for {$plan->code}.");
        };

        // PLUS 1: 100 mg today = ₹721 → 3 pieces = ₹2,163
        $plus1 = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always']);
        $this->assertStringContainsString('721.00', $refused($plus1, 1500, '9000000071'));
        $bill($plus1, 2163, '9000000072');
        $bond = Bond::where('plan_id', $plus1->id)->firstOrFail();
        $this->assertSame(3, (int) $bond->rd_pieces);
        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $this->assertEquals(2163.0, (float) $qr->cash_worth);            // the QR backs all three pieces
        $this->assertEquals(0.3, (float) $qr->gram_worth);

        // the price moves: the renewal is still 3 pieces, at the new price
        LiveRate::query()->update(['gold' => 8000]);
        $entry = app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $this->assertEquals(3 * 824.0, (float) $entry->value);           // 800 + 3 % = 824 each

        // PLUS 2: ₹1,500 multiples
        $plus2 = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'first_renewal', 'rd_step' => 1500, 'min_value' => 1500]);
        $refused($plus2, 2000, '9000000073');
        $bill($plus2, 4500, '9000000074');
        $this->assertSame(3, (int) Bond::where('plan_id', $plus2->id)->value('rd_pieces'));

        // PLUS 3: ₹1,000 minimum, ₹500 steps
        $plus3 = $this->rdPlan(['is_redeem' => false, 'rd_step' => 500, 'min_value' => 1000]);
        $refused($plus3, 500, '9000000075');
        $refused($plus3, 1200, '9000000076');
        $bill($plus3, 2500, '9000000077');
        $this->assertEquals(2500.0, (float) Bond::where('plan_id', $plus3->id)->value('value'));
    }

    /** The gold a scheme's gold share buys = the legacy cash→gold conversion, not worth ÷ rate. */
    public function test_sale_qr_grams_take_gst_and_bracket_charges_out(): void
    {
        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 1.001, 'wt_to' => 8, 'making_pct' => 2.5, 'wastage_pct' => 2.5]);
        $plan = Plan::create([
            'code' => 'SUBD', 'name' => ['en' => 'Sub Dealer'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 75, 'allocation_cont' => 25, 'allocation' => 25,
            'is_redeem' => true, 'is_contract' => false, 'is_active' => true,
        ]);

        app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $plan->id, 'gold_rate' => 12730,
            'customer' => ['name' => 'Sub D', 'phone' => '9000000098'],
            'cart' => [['material' => 'cash', 'net_total' => 100000, 'grand_total' => 100000]],
        ]);

        $qr = RedeemableQr::where('bond_id', Bond::where('plan_id', $plan->id)->value('id'))->firstOrFail();
        $this->assertEquals(25000.0, (float) $qr->cash_worth);
        $this->assertEqualsWithDelta(1.8159, (float) $qr->gram_worth, 0.0001);   // legacy QR for the same bill; ÷ rate alone gave 1.9639
    }

    /** PLUS2-style ('first_renewal'): no QR at bill; ONE QR at the first renewal only. */
    public function test_first_renewal_plan_mints_single_qr(): void
    {
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'first_renewal']);

        // Billing through SalesService mints NO QR for this plan.
        $invoice = app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $plan->id,
            'customer' => ['name' => 'Plus2 Cust', 'phone' => '9000000088'],
            'cart' => [['material' => 'cash', 'net_total' => 721, 'grand_total' => 721]],
        ]);
        $bond = Bond::where('plan_id', $plan->id)->firstOrFail();
        $this->assertDatabaseMissing('redeemable_qrs', ['bond_id' => $bond->id]);

        // First renewal mints the one-time QR…
        app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $this->assertEquals(1, RedeemableQr::where('bond_id', $bond->id)->count());

        // …later renewals do not.
        app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $this->assertEquals(1, RedeemableQr::where('bond_id', $bond->id)->count());
    }

    /** Item 12: fixed-amount RD cannot renew past its validity (joining + validity-1 renewals). */
    public function test_fixed_rd_blocks_renewal_past_validity(): void
    {
        $plan = $this->rdPlan(['rd_qr_grams' => 0.100, 'rd_qr_on' => 'always', 'validity_months' => 3]);
        $bond = $this->bond($plan, $this->member('RQ3'), 721);
        $svc = app(RdCollectionService::class);

        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1]);
    }

    /** Lord's board 2026-11-13: a PLUS 3 renewal is always the joining amount — not editable. */
    public function test_plus3_renewal_is_the_joining_amount_and_stops_at_validity(): void
    {
        $plan = $this->rdPlan(['validity_months' => 3, 'is_redeem' => false]);
        $bond = $this->bond($plan, $this->member('RQ4'), 1500);
        $svc = app(RdCollectionService::class);

        // whatever the counter types, one month's amount is collected
        $this->assertEquals(1500.0, (float) $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 3000])->value);
        $this->assertEquals(1500.0, (float) $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 2000])->value);
        $this->assertEquals(0, RedeemableQr::where('bond_id', $bond->id)->count());

        // joining + 2 renewals = 3 months: nothing left
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1500]);
    }

    /** Item 11: each renewal refreshes the contract body with the actual paid dates/amounts. */
    public function test_renewal_refreshes_contract_with_paid_rows(): void
    {
        $plan = $this->rdPlan(['code' => 'P200', 'is_redeem' => false]);   // routes into the savings template
        $member = $this->member('RQ5');
        $bond = $this->bond($plan, $member, 1500);
        $contract = MemberContract::create([
            'contract_no' => 'LJC-RQ5', 'bond_id' => $bond->id, 'member_id' => $member->id,
            'plan_id' => $plan->id, 'branch_id' => $this->branch->id, 'invoice_no' => $bond->invoice_no,
            'amount' => 1500, 'start_date' => now()->toDateString(),
            'end_date' => now()->addMonthsNoOverflow(12)->toDateString(), 'content' => '', 'status' => 'active',
        ]);

        // Chart semantics (board 2026-08-10): ONE row per calendar month from the bond
        // start — pay the renewal in month 2 so it lands on the chart.
        $paidOn = now()->addMonthsNoOverflow(1)->day(4);
        app(RdCollectionService::class)->collect([
            'bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1500,
            'paid_on' => $paidOn->toDateString(),
        ]);

        $content = $contract->fresh()->content;
        $this->assertStringContainsString('PAID ' . $paidOn->format('d/m/Y'), $content);  // renewal date printed
        $this->assertStringContainsString('RD Branch', $content);           // collecting branch printed
        // joining row prints the real amount — guards the epin_value "0.00"-is-truthy bug
        $this->assertStringContainsString('1,500.00', $content);
    }

    /** Item 10: the sale QR is worth the allocation_cont % of the billed value. */
    public function test_sale_qr_worth_uses_allocation_cont(): void
    {
        $plan = Plan::create([
            'code' => 'CONT25', 'name' => ['en' => 'Cont 25'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 75, 'allocation_cont' => 25,
            'is_redeem' => true, 'is_contract' => false, 'is_active' => true,
        ]);

        app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $plan->id,
            'customer' => ['name' => 'Cont Cust', 'phone' => '9000000099'],
            'cart' => [['material' => 'cash', 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        $bond = Bond::where('plan_id', $plan->id)->firstOrFail();
        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $this->assertEquals(257.50, (float) $qr->cash_worth);   // 25% of the ₹1,030 grand
    }
}
