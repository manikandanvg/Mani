<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Services\BranchOrderService;
use App\Services\Redeem\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'distributor']);
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
    }

    protected function member(string $code, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ], $attrs));
    }

    protected function plan(string $code, string $type = 'digital', array $extra = []): Plan
    {
        return Plan::create(array_merge(['code' => $code, 'name' => ['en' => "Plan {$code}"], 'plan_type' => 1, 'type' => $type, 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true], $extra));
    }

    protected function gold(string $code = 'G1'): CatalogProduct
    {
        return CatalogProduct::create(['code' => $code, 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 50, 'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'hsn_code' => '71082000', 'is_active' => true]);
    }

    /** Board 2026-11-14: a QR is redeemed for gold and silver items only — never cash, HQ included. */
    public function test_cash_stock_cannot_be_redeemed_even_at_head_office(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $plan = $this->plan('501', 'digital');
        $member = $this->member('RC1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $hq->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RC1', 'status' => 'active']);
        $cash = CatalogProduct::create(['code' => 'CASHX', 'name' => ['en' => 'CASH'], 'material' => 'cash', 'default_weight' => 0, 'gst_pct' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $hq->id, 'catalog_product_id' => $cash->id, 'quantity' => 1000000]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $hq->id, 'invoice_no' => 'INV-RC1', 'qr_code' => 'QRC1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        try {
            $svc->redeem(['qr_code' => 'QRC1', 'branch_id' => $hq->id, 'otp' => $qr->fresh()->otp,
                'lines' => [['catalog_product_id' => $cash->id, 'unit_weight' => 1, 'quantity' => 50000]]]);
            $this->fail('Cash stock was redeemed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('gold and silver items only', $e->getMessage());
        }
        $this->assertEquals('pending', $qr->fresh()->status);
        $this->assertEquals(1000000, (float) Stock::where('catalog_product_id', $cash->id)->value('quantity'));
    }

    /** Mode B: manual cart redeem deducts the redeeming branch's stock + pays GM margin, no bill margin. */
    public function test_mode_b_redeem_deducts_stock_and_pays_gm_margin(): void
    {
        $branch = Branch::create(['name' => 'Redeem Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('500', 'digital');
        $member = $this->member('RB1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RB1', 'status' => 'active']);
        $cp = $this->gold();
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB1', 'qr_code' => 'QRB1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);

        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $otp = $qr->fresh()->otp;

        // 20 pieces of 1g gold @ ₹5000 = ₹100000 (≥ ₹50000 worth)
        $invoice = $svc->redeem([
            'qr_code' => 'QRB1', 'branch_id' => $branch->id, 'otp' => $otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]],
        ]);

        $this->assertEquals('redeemed', $qr->fresh()->status);
        $this->assertEquals($branch->id, $qr->fresh()->redeem_branch_id);
        // stock 100 − 20g = 80
        $this->assertEquals(80, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        // GM margin: 20g × ₹50 = ₹1000 to the redeeming branch (com_type 2 = gold), NO bill margin (com_type 1)
        $this->assertEquals(1000, (float) $branch->fresh()->gold_gm_margin);
        $this->assertEquals(1000, (float) \App\Models\ResellerCommission::where('branch_id', $branch->id)->where('com_type_id', 2)->sum('com_value'));
        $this->assertEquals(0, \App\Models\ResellerCommission::where('com_type_id', 1)->count());
        // ₹1,00,000 of goods + 3 % GST ON TOP = ₹1,03,000 (Lord's board 2026-11-14)
        $this->assertEquals(103000, (float) $invoice->grand_total);
        $this->assertEquals(100000, (float) $invoice->taxable_total);
        $this->assertEquals(3000, (float) $invoice->tax_total);
        $this->assertEquals(1500, (float) $invoice->cgst);
        // Description of Goods = the Catalog Product name in capitals (user 2026-11-13) …
        $this->assertEquals('GOLD 1G', $invoice->lines()->value('description'));
        // … and the 100 mg piece is "100 MG GOLD", never the old "1 MG GOLD"
        $mg = CatalogProduct::create(['code' => 'G100', 'name' => ['en' => '100 mg Gold'], 'material' => 'gold', 'default_weight' => 0.1, 'gst_pct' => 3, 'is_active' => true]);
        $this->assertEquals('100 MG GOLD', RedemptionService::lineLabel($mg, 0.1));
    }

    /** A Mode-B redemption can raise a restock order that, once approved, replenishes the branch. */
    public function test_restock_order_from_redemption_replenishes_branch_on_approval(): void
    {
        $branch = Branch::create(['name' => 'Restock Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('503', 'digital');
        $member = $this->member('RB4');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RB4', 'status' => 'active']);
        $cp = $this->gold('G4');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB4', 'qr_code' => 'QRB4', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);

        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $invoice = $svc->redeem([
            'qr_code' => 'QRB4', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]],
        ]);

        // 20g redeemed → branch stock 80; the invoice opens pending (awaiting restock approval).
        $this->assertEquals(80, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertEquals('pending', $invoice->fresh()->payment_mode);

        // Raise the restock order — tagged as redemption-sourced and linked to the invoice.
        $orders = app(BranchOrderService::class);
        $request = $orders->createFromRedemption($invoice, null);
        $this->assertEquals(BranchOrderService::SOURCE_REDEMPTION, $request->source);
        $this->assertEquals($invoice->id, (int) $request->source_ref);
        $this->assertEquals($branch->id, (int) $request->branch_id);
        $this->assertEquals('pending', $request->status);
        $this->assertEquals(1, (int) $request->no_of_items);
        $this->assertEquals(20.0, (float) $request->lines->first()->weight);

        // Idempotent — a second attempt for the same invoice is rejected.
        try {
            $orders->createFromRedemption($invoice, null);
            $this->fail('expected duplicate restock to be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // Approval (no source branch → direct from HQ) adds the 20g back: 80 → 100,
        // and closes the loop — the redemption invoice flips pending → passed.
        $orders->approve($request->fresh('lines'));
        $this->assertEquals('approved', $request->fresh()->status);
        $this->assertEquals(100, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertEquals('passed', $invoice->fresh()->payment_mode);
    }

    /** Mode A (metal-purchase): redeem moves no stock, but a re-order CAN still be raised,
     *  using the redemption line quantity as-is (unit_weight is not recorded for Mode A). */
    public function test_mode_a_redemption_can_raise_restock_using_line_quantity(): void
    {
        $branch = Branch::create(['name' => 'A Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('206', 'gold');   // metal-purchase plan (Mode A)
        $member = $this->member('RB5');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RB5', 'status' => 'active']);
        $cp = $this->gold('G5');
        $inv = SalesInvoice::create(['invoice_no' => 'INV-RB5', 'date' => now(), 'customer_member_id' => $member->id, 'branch_id' => $branch->id, 'grand_total' => 50000]);
        SaleLine::create(['invoice_id' => $inv->id, 'catalog_product_id' => $cp->id, 'material' => 'gold', 'description' => '1 G GOLD', 'qty' => 10, 'rate' => 5000, 'line_total' => 51500]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB5', 'qr_code' => 'QRA5', 'qr_mode' => 'gold', 'cash_worth' => 50000, 'status' => 'pending']);

        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $invoice = $svc->redeem(['qr_code' => 'QRA5', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp]);

        // Mode A redeem itself moves no stock.
        $this->assertEquals(100, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));

        // A re-order can still be raised; the line quantity (10) is used as-is for the weight.
        $orders = app(BranchOrderService::class);
        $request = $orders->createFromRedemption($invoice, null);
        $this->assertEquals(BranchOrderService::SOURCE_REDEMPTION, $request->source);
        $this->assertEquals($invoice->id, (int) $request->source_ref);
        $this->assertEquals(10.0, (float) $request->lines->first()->weight);
        $this->assertEquals($invoice->invoice_no, $request->redemptionInvoice->invoice_no);

        // Approval adds the re-ordered quantity and flips the invoice pending → passed.
        $orders->approve($request->fresh('lines'));
        $this->assertEquals(110, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertEquals('passed', $invoice->fresh()->payment_mode);
    }

    /** Mode B worth gate: cart total below the QR worth is rejected. */
    public function test_mode_b_rejects_below_worth(): void
    {
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('501', 'digital');
        $member = $this->member('RB2');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RB2', 'status' => 'active']);
        $cp = $this->gold('G2');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB2', 'qr_code' => 'QRB2', 'qr_mode' => 'cash', 'cash_worth' => 100000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->redeem(['qr_code' => 'QRB2', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 5]]]); // ₹25k < ₹100k
    }

    /** Mode A (P206): cart from the original sale, billing-branch only, NO stock move / NO margin. */
    public function test_mode_a_206_uses_sale_cart_no_stock_no_margin_and_branch_locked(): void
    {
        $billingBranch = Branch::create(['name' => 'Billing', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Other', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('206', 'gold');
        $member = $this->member('RA1');
        $cp = $this->gold('G206');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $billingBranch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RA1', 'status' => 'active']);
        $inv = SalesInvoice::create(['invoice_no' => 'INV-RA1', 'date' => now(), 'customer_member_id' => $member->id, 'branch_id' => $billingBranch->id, 'grand_total' => 50000]);
        SaleLine::create(['invoice_id' => $inv->id, 'catalog_product_id' => $cp->id, 'material' => 'gold', 'description' => '1 G GOLD', 'qty' => 10, 'rate' => 5000, 'line_total' => 51500]);
        Stock::create(['branch_id' => $billingBranch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $billingBranch->id, 'invoice_no' => 'INV-RA1', 'qr_code' => 'QRA1', 'qr_mode' => 'gold', 'cash_worth' => 50000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $otp = $qr->fresh()->otp;

        // wrong branch is blocked
        try {
            $svc->redeem(['qr_code' => 'QRA1', 'branch_id' => $otherBranch->id, 'otp' => $otp, 'lines' => []]);
            $this->fail('expected branch-lock rejection');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // billing branch: redeems using the sale's cart, no stock move, no margin
        $invoice = $svc->redeem(['qr_code' => 'QRA1', 'branch_id' => $billingBranch->id, 'otp' => $otp]);

        $this->assertEquals('redeemed', $qr->fresh()->status);
        $this->assertCount(1, $invoice->lines);
        $this->assertEquals(100, (float) Stock::where('branch_id', $billingBranch->id)->where('catalog_product_id', $cp->id)->value('quantity')); // unchanged
        $this->assertEquals(0, \App\Models\ResellerCommission::count());
        $this->assertEquals(0, (float) $billingBranch->fresh()->gold_gm_margin);
    }

    /** Wrong OTP is rejected. */
    public function test_wrong_otp_rejected(): void
    {
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('502', 'digital');
        $member = $this->member('RB3');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 1000, 'invoice_no' => 'INV-RB3', 'status' => 'active']);
        $cp = $this->gold('G3');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB3', 'qr_code' => 'QRB3', 'qr_mode' => 'cash', 'cash_worth' => 1000, 'status' => 'pending']);
        app(RedemptionService::class)->generateOtp($qr);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RedemptionService::class)->redeem(['qr_code' => 'QRB3', 'branch_id' => $branch->id, 'otp' => '00000',
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 1]]]);
    }

    /** Silver purchase plan (P211, type silver) is Mode A too: billing-branch-locked. */
    public function test_silver_purchase_plan_is_mode_a_branch_locked(): void
    {
        $billing = Branch::create(['name' => 'AgBilling', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $other = Branch::create(['name' => 'AgOther', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('211', 'silver');
        $member = $this->member('RAG1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $billing->id, 'bond_date' => now(), 'value' => 5000, 'invoice_no' => 'INV-RAG1', 'status' => 'active']);
        RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $billing->id, 'invoice_no' => 'INV-RAG1', 'qr_code' => 'QRAG1', 'qr_mode' => 'gold', 'cash_worth' => 5000, 'status' => 'pending']);

        $ctx = app(RedemptionService::class)->lookup('QRAG1', $billing->id);
        $this->assertEquals('A', $ctx['mode']);
        $this->assertTrue($ctx['branch_locked']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RedemptionService::class)->lookup('QRAG1', $other->id);   // wrong branch blocked
    }

    /** Item 3: Mode B consumes the redeeming branch's OWN stock — insufficient stock aborts. */
    public function test_mode_b_redeem_rejects_insufficient_branch_stock(): void
    {
        $branch = Branch::create(['name' => 'NoStock', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('560', 'digital');
        $member = $this->member('RB9');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 1000, 'invoice_no' => 'INV-RB9', 'status' => 'active']);
        $cp = $this->gold('G9');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 5]);   // holds only 5g
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-RB9', 'qr_code' => 'QRB9', 'qr_mode' => 'cash', 'cash_worth' => 1000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->redeem(['qr_code' => 'QRB9', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 10]]]);   // wants 10g
    }

    /** Digital plan: redeem opens a dealer branch + distributor login seeded with the stock; idempotent. */
    public function test_dealer_account_created_and_seeded_then_idempotent(): void
    {
        $branch = Branch::create(['name' => 'Redeem Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('510', 'digital', ['useraccess' => true]);   // dealer tick allowed (schema v2)
        $member = $this->member('DLR1', ['city' => 'Trichy']);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 100000, 'invoice_no' => 'INV-DLR1', 'status' => 'active']);
        $cp = $this->gold('GD1');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 1000]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-DLR1', 'qr_code' => 'QDLR1', 'qr_mode' => 'cash', 'cash_worth' => 100000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        $invoice = $svc->redeem([
            'qr_code' => 'QDLR1', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'create_dealer' => true, 'dealer' => ['branch_name' => 'DLR1 Jewels', 'gst_no' => '33ABCDE9999Z1'],
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]],
        ]);

        $this->assertTrue($invoice->fresh()->dealer_created);
        $dealerBranch = Branch::where('name', 'DLR1 Jewels')->first();
        $this->assertNotNull($dealerBranch);
        $this->assertDatabaseHas('users', ['email' => 'dlr1@lordicl', 'branch_id' => $dealerBranch->id]);
        // redeemed 20g seeded into the dealer's own stock
        $this->assertEquals(20, (float) Stock::where('branch_id', $dealerBranch->id)->where('catalog_product_id', $cp->id)->value('quantity'));

        // Second redemption for the same member must NOT create a second branch/user.
        $qr2 = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-DLR1', 'qr_code' => 'QDLR2', 'qr_mode' => 'cash', 'cash_worth' => 5000, 'status' => 'pending']);
        $svc->generateOtp($qr2);
        $svc->redeem(['qr_code' => 'QDLR2', 'branch_id' => $branch->id, 'otp' => $qr2->fresh()->otp,
            'create_dealer' => true, 'dealer' => ['branch_name' => 'Should Not Create'],
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 2]]]);

        $this->assertEquals(1, Branch::where('name', 'DLR1 Jewels')->count());
        $this->assertNull(Branch::where('name', 'Should Not Create')->first());
        // stock added to the existing dealer branch (20 + 2 = 22)
        $this->assertEquals(22, (float) Stock::where('branch_id', $dealerBranch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
    }

    /** Buyer GST: the typed value prints; an existing on-record dealer GST takes precedence. */
    public function test_buyer_gst_typed_then_existing_takes_precedence(): void
    {
        $branch = Branch::create(['name' => 'GST Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('540', 'digital');
        $member = $this->member('GST1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-GST1', 'status' => 'active']);
        $cp = $this->gold('GGST');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);

        // 1) No GST on record → the typed value is stored/printed (and normalised to upper).
        $qr1 = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-GST1', 'qr_code' => 'QGST1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr1);
        $inv1 = $svc->redeem(['qr_code' => 'QGST1', 'branch_id' => $branch->id, 'otp' => $qr1->fresh()->otp,
            'buyer_gst' => '33abcde1234f1z5',
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]]]);
        $this->assertEquals('33ABCDE1234F1Z5', $inv1->buyer_gst);

        // Make this member a dealer with a real GST on record.
        $dealerBranch = Branch::create(['name' => 'GST1 Dealer', 'country' => 'IN', 'level' => 'reseller', 'gst_no' => '33ONRECORD9Z9', 'is_active' => true]);
        \App\Models\User::create(['name' => 'd', 'email' => 'gst1@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $dealerBranch->id, 'member_code' => 'GST1']);

        // 2) GST exists on record → it wins, even if the operator types a different one.
        $qr2 = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-GST1', 'qr_code' => 'QGST2', 'qr_mode' => 'cash', 'cash_worth' => 5000, 'status' => 'pending']);
        $svc->generateOtp($qr2);
        $inv2 = $svc->redeem(['qr_code' => 'QGST2', 'branch_id' => $branch->id, 'otp' => $qr2->fresh()->otp,
            'buyer_gst' => '33TYPED0000Z0',
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 2]]]);
        $this->assertEquals('33ONRECORD9Z9', $inv2->buyer_gst);
    }

    /**
     * User 2026-11-13 (invoice 4598): an invoice billed at a legacy QR-date rate while the screen
     * carted at the live rate is LISTED (never changed) by redemptions:audit-cart-rate.
     */
    public function test_audit_cart_rate_lists_mismatch_and_changes_nothing(): void
    {
        $branch = Branch::create(['name' => 'Audit Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('540', 'digital');
        $member = $this->member('AUD1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 100000, 'invoice_no' => '4770', 'status' => 'active']);
        $cp = $this->gold('GAUD');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = (new RedeemableQr)->forceFill(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => '4770',
            'qr_code' => 'QAUD1', 'qr_mode' => 'gold', 'source' => RedeemableQr::SOURCE_LEGACY, 'priced_on' => '2026-02-01', 'frozen_rate' => 6000,
            'cash_worth' => 100000, 'status' => 'pending']);
        $qr->save();
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        // billed at the QR-date ₹6,000: 20 g = ₹1,20,000 + 3 % = ₹1,23,600 (the screen had shown ₹1,03,000 at ₹5,000)
        $invoice = $svc->redeem(['qr_code' => 'QAUD1', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]]]);
        $this->assertEquals(123600, (float) $invoice->grand_total);

        // the list is read-only: it reports the screen total (₹1,03,000) and the excess over the QR worth,
        // and the invoice keeps its frozen-price figures
        $this->artisan('redemptions:audit-cart-rate', ['--id' => $invoice->id])
            ->expectsOutputToContain('above the QR worth: ₹23,600.00')
            ->assertSuccessful();
        $invoice->refresh();
        $this->assertEquals(123600, (float) $invoice->grand_total);
        $this->assertEquals(6000, (float) $invoice->frozen_gold_rate);

        // already at the frozen price → re-calibration leaves it alone
        $this->artisan('redemptions:recalibrate', ['--apply' => true])->expectsOutputToContain('0 re-calibrated')->assertSuccessful();
        $this->assertEquals(123600, (float) $invoice->fresh()->grand_total);

        // an invoice raised 13–14 Nov 2026 at the frozen price with the GST taken OUT of the value
        // (grand = rows) keeps its figures — the board's 14 Nov rule is for new invoices only
        $inside = ['taxable_total' => 116504.85, 'cgst' => 1747.58, 'sgst' => 1747.57, 'tax_total' => 3495.15, 'grand_total' => 120000];
        \Illuminate\Support\Facades\DB::table('redemption_invoices')->where('id', $invoice->id)->update($inside);
        \Illuminate\Support\Facades\DB::table('redemption_lines')->where('redemption_invoice_id', $invoice->id)->update(['line_total' => 120000]);
        $this->artisan('redemptions:recalibrate', ['--apply' => true])->expectsOutputToContain('0 re-calibrated')->assertSuccessful();
        $this->assertEquals(120000, (float) $invoice->fresh()->grand_total);

        // … unless Head Office asks for that one invoice: --gst-on-top puts the GST on top of its rows
        $this->artisan('redemptions:recalibrate', ['--id' => $invoice->id, '--gst-on-top' => true])->expectsOutputToContain('1 differ from')->assertSuccessful();
        $this->assertEquals(120000, (float) $invoice->fresh()->grand_total);   // dry run
        $this->artisan('redemptions:recalibrate', ['--id' => $invoice->id, '--gst-on-top' => true, '--apply' => true])->expectsOutputToContain('1 re-calibrated')->assertSuccessful();
        $this->assertEquals([120000.0, 3600.0, 123600.0], [(float) $invoice->fresh()->taxable_total, (float) $invoice->fresh()->tax_total, (float) $invoice->fresh()->grand_total]);
        $this->assertEquals(123600, (float) $invoice->lines()->sum('line_total'));

        // set to the LIVE price (₹5,000) by an earlier correction → brought back to the frozen price
        \Illuminate\Support\Facades\DB::table('redemption_invoices')->where('id', $invoice->id)
            ->update(['gold_rate' => 5000, 'taxable_total' => 97087.38, 'cgst' => 1456.31, 'sgst' => 1456.31, 'tax_total' => 2912.62, 'grand_total' => 100000]);
        \Illuminate\Support\Facades\DB::table('redemption_lines')->where('redemption_invoice_id', $invoice->id)
            ->update(['rate' => 5000, 'amount' => 100000, 'line_total' => 100000]);

        $this->artisan('redemptions:recalibrate')->expectsOutputToContain('1 differ from')->assertSuccessful();
        $this->assertEquals(100000, (float) $invoice->fresh()->grand_total);   // dry run writes nothing

        $this->artisan('redemptions:recalibrate', ['--apply' => true])->expectsOutputToContain('1 re-calibrated')->assertSuccessful();
        $invoice->refresh();
        $this->assertEquals(123600, (float) $invoice->grand_total);
        $this->assertEquals(120000, (float) $invoice->taxable_total);
        $this->assertEquals(3600, (float) $invoice->tax_total);
        $this->assertEquals(1800, (float) $invoice->cgst);
        $this->assertEquals(6000, (float) $invoice->frozen_gold_rate);
        $this->assertEquals(123600, (float) $invoice->lines()->sum('line_total'));
        $this->assertEquals(80, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));   // stock untouched
    }

    /**
     * Final confirmation, user 2026-11-13: the frozen price is the gold / silver price of the
     * BILLED date — a gold item at that day's gold price, a silver item at that day's silver
     * price — and the bill also carries the live market price of the redemption day.
     */
    public function test_redemption_prices_gold_and_silver_at_the_billed_date_and_shows_the_live_price(): void
    {
        $branch = Branch::create(['name' => 'Frozen Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('550', 'digital');
        $member = $this->member('FRZ1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-10-17', 'value' => 50000, 'invoice_no' => 'INV-FRZ1', 'status' => 'active']);
        // billed on 17 Oct at gold ₹6,000 / silver ₹120; today's live price is ₹5,000 / ₹100
        SalesInvoice::create(['invoice_no' => 'INV-FRZ1', 'date' => '2026-10-17', 'customer_member_id' => $member->id, 'branch_id' => $branch->id, 'grand_total' => 50000, 'gold_rate' => 6000, 'silver_rate' => 120]);
        $gold = $this->gold('GFRZ');
        $silver = CatalogProduct::create(['code' => 'SFRZ', 'name' => ['en' => '10 g Silver'], 'material' => 'silver', 'default_weight' => 10, 'gm_margin' => 0, 'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 100]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $silver->id, 'quantity' => 100]);
        // a silver QR staged the legacy way: the GOLD rate sits in its frozen rate
        $qr = (new RedeemableQr)->forceFill(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-FRZ1',
            'qr_code' => 'QFRZ1', 'qr_mode' => 'silver', 'source' => RedeemableQr::SOURCE_LEGACY, 'priced_on' => '2026-10-17', 'frozen_rate' => 6000,
            'cash_worth' => 50000, 'status' => 'pending']);
        $qr->save();
        $svc = app(RedemptionService::class);
        $this->assertEquals(['gold' => 6000.0, 'silver' => 120.0, 'date' => '2026-10-17'], $svc->billedRates($qr));
        $svc->generateOtp($qr);

        $invoice = $svc->redeem(['qr_code' => 'QFRZ1', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp, 'lines' => [
            ['catalog_product_id' => $gold->id, 'unit_weight' => 1, 'quantity' => 10],      // 10 × 6,000 = 60,000
            ['catalog_product_id' => $silver->id, 'unit_weight' => 10, 'quantity' => 5],    // 5 × 10 g × 120 = 6,000
        ]])->fresh();

        $this->assertEquals(67980, (float) $invoice->grand_total);   // 66,000 + 3 % GST on top
        $lines = $invoice->lines()->orderBy('id')->get();
        $this->assertEquals(6000, (float) $lines[0]->rate, 'gold item at the billed-date GOLD price');
        $this->assertEquals(1200, (float) $lines[1]->rate, 'silver item at the billed-date SILVER price — never the gold price');
        // on top of the bill: the live price; alongside it the frozen prices the calculation used
        $this->assertEquals([5000.0, 100.0], [(float) $invoice->gold_rate, (float) $invoice->silver_rate]);
        $this->assertEquals([6000.0, 120.0], [(float) $invoice->frozen_gold_rate, (float) $invoice->frozen_silver_rate]);
        $this->assertEquals('2026-10-17', $invoice->frozen_on->toDateString());
        $this->assertStringStartsWith('%PDF', app(\App\Services\Redeem\RedemptionInvoicePdf::class)->download($invoice)->getContent());
    }

    /** The tax-invoice PDF renders, and rupees-in-words uses Indian (lakh/crore) grouping. */
    public function test_invoice_pdf_amount_in_words_and_renders(): void
    {
        $pdf = app(\App\Services\Redeem\RedemptionInvoicePdf::class);

        $this->assertEquals('One Lakh Three Thousand Rupees Only', $pdf->rupeesInWords(103000));
        $this->assertEquals('Twenty Five Thousand Rupees Only', $pdf->rupeesInWords(25000));
        $this->assertEquals('One Crore Rupees Only', $pdf->rupeesInWords(10000000));
        $this->assertEquals('Five Hundred Rupees and Fifty Paise Only', $pdf->rupeesInWords(500.50));
        $this->assertEquals('Zero Rupees Only', $pdf->rupeesInWords(0));

        // End-to-end: redeem, then the PDF service produces a non-empty document.
        $branch = Branch::create(['name' => 'PDF Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = $this->plan('520', 'digital');
        $member = $this->member('PDF1');
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-PDF1', 'status' => 'active']);
        $cp = $this->gold('GPDF');
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-PDF1', 'qr_code' => 'QPDF1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $invoice = $svc->redeem(['qr_code' => 'QPDF1', 'branch_id' => $branch->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]]]);

        $output = $pdf->download($invoice)->getContent();
        $this->assertStringStartsWith('%PDF', $output);
    }
}
