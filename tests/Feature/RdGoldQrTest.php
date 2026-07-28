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

    /** Item 12: variable RD (PLUS3-style) takes whole multiples of the monthly and caps the total. */
    public function test_variable_rd_multiples_and_total_cap(): void
    {
        $plan = $this->rdPlan(['validity_months' => 11, 'is_redeem' => false]);
        $bond = $this->bond($plan, $this->member('RQ4'), 1500);
        $svc = app(RdCollectionService::class);

        // The item-11 example: 1500, then a 3000 double-due — both fine.
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 1500]);
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 3000]);

        // Not a multiple of ₹1500 → rejected.
        try {
            $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 2000]);
            $this->fail('Non-multiple renewal should have been rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }

        // Cap: total is 11 × 1500 = 16,500; joining 1500 + 4500 collected → 10,500 headroom.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->collect(['bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 12000]);
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

        app(RdCollectionService::class)->collect([
            'bond_id' => $bond->id, 'branch_id' => $this->branch->id, 'amount' => 3000,
            'paid_on' => '2026-06-04',
        ]);

        $content = $contract->fresh()->content;
        $this->assertStringContainsString('04/06/2026', $content);          // renewal date printed
        $this->assertStringContainsString('3,000.00', $content);            // renewal amount printed
        $this->assertStringContainsString('RD Branch', $content);           // collecting branch printed
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
