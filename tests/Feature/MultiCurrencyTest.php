<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Currency;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\ResellerCommission;
use App\Models\Stock;
use App\Services\CommissionApprovalService;
use App\Services\CommissionService;
use App\Services\RdCollectionService;
use App\Services\Redeem\RedemptionService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foreign-distributor multi-currency: a London branch transacts in EUR with VAT, the
 * network maths stay normalised to the INR base, and commissions settle in the EARNER's
 * home currency at the rate frozen on the sale.
 */
class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Pin EUR for clean arithmetic: 1 INR = 0.01 EUR  →  1 EUR = ₹100.
        Currency::updateOrCreate(['code' => 'EUR'], ['rate_to_base' => 0.01, 'is_base' => false, 'is_active' => true]);

        $this->plan = Plan::create([
            'code' => 'MC', 'name' => ['en' => 'MC'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 12,
            'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => ['10'], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 12, 'billing_margin' => 2,
            'is_active' => true,
        ]);
    }

    protected function member(string $code, ?int $uplineId, int $branchId): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'upline_id' => $uplineId, 'placement' => 'level', 'branch_id' => $branchId,
            'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active',
        ]);
    }

    protected function bond(Member $m, float $valueInr): void
    {
        Bond::create([
            'member_id' => $m->id, 'plan_id' => $this->plan->id, 'bond_date' => now(),
            'value' => $valueInr, 'cbc_count' => 0, 'lvlcom_count' => 12, 'status' => 'active',
        ]);
    }

    /** A London (EUR / VAT) sale: invoice in EUR with VAT, bond value normalised to INR, IC to an INR upline. */
    public function test_london_eur_sale_with_vat_and_inr_upline(): void
    {
        $india = Branch::create(['name' => 'India Shop', 'country' => 'IN', 'currency_code' => 'INR', 'tax_regime' => 'gst', 'is_active' => true]);
        $london = Branch::create(['name' => 'London', 'country' => 'GB', 'currency_code' => 'EUR', 'tax_regime' => 'vat', 'vat_pct' => 20, 'is_active' => true]);

        $upline = $this->member('UP', null, $india->id);   // INR earner
        $this->bond($upline, 10_000_000);                  // big INR headroom

        $invoice = app(SalesService::class)->generateInvoice([
            'branch_id' => $london->id,
            'plan_id' => $this->plan->id,
            'upline_code' => 'UP', 'referrer_code' => 'UP',
            'gold_rate' => 70,
            'customer' => ['name' => 'London Cust', 'phone' => '9000000010'],
            'cart' => [['material' => 'gold', 'weight' => 10, 'net_total' => 1000, 'grand_total' => 1000]],
        ]);

        // Invoice is in EUR with a single 20% VAT line (no CGST/SGST), rate frozen at 100.
        $this->assertEquals('EUR', $invoice->currency_code);
        $this->assertEquals(100.0, (float) $invoice->fx_rate);
        $this->assertEquals('vat', $invoice->tax_regime);
        $this->assertEquals(1000.0, (float) $invoice->cross_total);
        $this->assertEquals(200.0, (float) $invoice->tax_total);     // 20% of 1000
        $this->assertEquals(0.0, (float) $invoice->cgst);
        $this->assertEquals(0.0, (float) $invoice->sgst);
        $this->assertEquals(1200.0, (float) $invoice->grand_total);  // EUR

        // Bond value is the allocation in INR base: 1000 EUR × 100 = 100,000.
        $bond = Bond::where('member_id', Member::where('name', 'London Cust')->value('id'))->firstOrFail();
        $this->assertEquals(100000.0, (float) $bond->value);

        // IC to the INR upline: 10% of the INR allocation = 10,000, stamped INR / 1.0.
        $ic = CommissionLedger::where('member_id', $upline->id)->where('type', 'IC')->firstOrFail();
        $this->assertEquals(10000.0, (float) $ic->amount);
        $this->assertEquals('INR', $ic->currency_code);
        $this->assertEquals(1.0, (float) $ic->fx_rate);

        // Bill margin: 2% of 1000 EUR = 20 EUR shown on the branch; stored INR-base 2,000.
        $this->assertDatabaseHas('reseller_commissions', ['invoice_no' => $invoice->invoice_no, 'com_value' => 2000.00, 'currency_code' => 'EUR']);
        $this->assertEquals(20.0, (float) $london->fresh()->bill_margin);

        // Approving the INR upline's IC credits an INR wallet, net of 5%+5%.
        app(CommissionApprovalService::class)->approve($ic->fresh());
        $wallet = MemberWallet::where('member_id', $upline->id)->firstOrFail();
        $this->assertEquals('INR', $wallet->currency_code);
        $this->assertEquals(9000.0, (float) $wallet->cash_balance);   // 10000 - 500 TDS - 500 svc
    }

    /** A London (EUR) earner on an INR sale is paid in EUR at the frozen sale-time rate. */
    public function test_eur_earner_paid_in_eur_at_frozen_rate(): void
    {
        $india = Branch::create(['name' => 'India Shop 2', 'country' => 'IN', 'currency_code' => 'INR', 'tax_regime' => 'gst', 'is_active' => true]);
        $london = Branch::create(['name' => 'London 2', 'country' => 'GB', 'currency_code' => 'EUR', 'tax_regime' => 'vat', 'vat_pct' => 20, 'is_active' => true]);

        $upline = $this->member('EURUP', null, $london->id);   // EUR earner
        $this->bond($upline, 10_000_000);                      // INR-base headroom

        app(SalesService::class)->generateInvoice([
            'branch_id' => $india->id,
            'plan_id' => $this->plan->id,
            'upline_code' => 'EURUP', 'referrer_code' => 'EURUP',
            'customer' => ['name' => 'India Cust', 'phone' => '9000000011'],
            'cart' => [['material' => 'gold', 'weight' => 1, 'net_total' => 1000, 'grand_total' => 1030]],
        ]);

        // IC amount is INR base (100), but stamped with the earner's EUR + frozen rate 100.
        $ic = CommissionLedger::where('member_id', $upline->id)->where('type', 'IC')->firstOrFail();
        $this->assertEquals(100.0, (float) $ic->amount);   // 10% of 1000 INR allocation
        $this->assertEquals('EUR', $ic->currency_code);
        $this->assertEquals(100.0, (float) $ic->fx_rate);

        // Paid in EUR: 100 INR / 100 = €1.00 gross → €0.90 net after 5%+5%.
        app(CommissionApprovalService::class)->approve($ic->fresh());
        $wallet = MemberWallet::where('member_id', $upline->id)->firstOrFail();
        $this->assertEquals('EUR', $wallet->currency_code);
        $this->assertEquals(0.90, (float) $wallet->cash_balance);
    }

    /** A Mode-B redemption at the London branch prices in EUR at the GB rate, VAT line, frozen stamp. */
    public function test_eur_redemption_prices_in_eur_with_vat_and_frozen_stamp(): void
    {
        $london = Branch::create(['name' => 'London Redeem', 'country' => 'GB', 'currency_code' => 'EUR', 'tax_regime' => 'vat', 'vat_pct' => 20, 'level' => 'reseller', 'is_active' => true]);
        LiveRate::create(['country' => 'GB', 'currency_code' => 'EUR', 'gold' => 50, 'silver' => 1, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        $member = $this->member('RDM1', null, $london->id);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $this->plan->id, 'branch_id' => $london->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-RDM1', 'status' => 'active']);
        $cp = CatalogProduct::create(['code' => 'MCG', 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 50, 'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'hsn_code' => '71082000', 'is_active' => true]);
        Stock::create(['branch_id' => $london->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        // cash_worth is INR base: ₹50,000 = €500 at the pinned rate.
        $qr = RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $london->id, 'invoice_no' => 'INV-RDM1', 'qr_code' => 'QMC1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);

        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);

        // 20 × 1g gold @ €50 = €1,000 taxable (≥ €500 worth) → 20% VAT €200 → €1,200.
        $invoice = $svc->redeem([
            'qr_code' => 'QMC1', 'branch_id' => $london->id, 'otp' => $qr->fresh()->otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]],
        ]);

        $this->assertEquals('EUR', $invoice->currency_code);
        $this->assertEquals(100.0, (float) $invoice->fx_rate);
        $this->assertEquals('vat', $invoice->tax_regime);
        $this->assertEquals(1000.0, (float) $invoice->taxable_total);
        $this->assertEquals(200.0, (float) $invoice->tax_total);
        $this->assertEquals(0.0, (float) $invoice->cgst);
        $this->assertEquals(0.0, (float) $invoice->sgst);
        $this->assertEquals(1200.0, (float) $invoice->grand_total);
        $this->assertEquals(50.0, (float) $invoice->gold_rate);   // the GB feed, in EUR

        // GM margin: 20g × ₹50/g = ₹1,000 INR base in the ledger, €10 on the branch balance.
        $gm = ResellerCommission::where('branch_id', $london->id)->where('com_type_id', 2)->firstOrFail();
        $this->assertEquals(1000.0, (float) $gm->com_value);
        $this->assertEquals('EUR', $gm->currency_code);
        $this->assertEquals(100.0, (float) $gm->fx_rate);
        $this->assertEquals(10.0, (float) $london->fresh()->gold_gm_margin);
    }

    /** An RD installment collected in London: entry in EUR with the frozen rate, margin INR base. */
    public function test_eur_rd_collection_stamps_entry_and_margin(): void
    {
        $london = Branch::create(['name' => 'London RD', 'country' => 'GB', 'currency_code' => 'EUR', 'tax_regime' => 'vat', 'vat_pct' => 20, 'level' => 'reseller', 'is_active' => true]);
        $rdPlan = Plan::create([
            'code' => 'MCRD', 'name' => ['en' => 'MC RD'], 'plan_type' => 2, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 12,
            'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 12, 'billing_margin' => 2,
            'is_active' => true,
        ]);
        $member = $this->member('RDC1', null, $london->id);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $rdPlan->id, 'branch_id' => $london->id, 'bond_date' => now(), 'value' => 0, 'status' => 'active']);

        $entry = app(RdCollectionService::class)->collect([
            'bond_id' => $bond->id, 'branch_id' => $london->id, 'amount' => 100,   // €100
        ]);

        // The entry records the money as it changed hands: €100 at the frozen rate.
        $this->assertEquals(100.0, (float) $entry->value);
        $this->assertEquals('EUR', $entry->currency_code);
        $this->assertEquals(100.0, (float) $entry->fx_rate);

        // Bill margin 2%: €2 on the branch balance, ₹200 INR base in the ledger.
        $com = ResellerCommission::where('branch_id', $london->id)->where('com_type_id', 2)->firstOrFail();
        $this->assertEquals(200.0, (float) $com->com_value);
        $this->assertEquals('EUR', $com->currency_code);
        $this->assertEquals(2.0, (float) $london->fresh()->bill_margin);
    }

    /** CBC freezes the earner's rate at ISSUE — a later rate change must not move the payout. */
    public function test_cbc_pays_at_the_rate_frozen_at_issue(): void
    {
        $london = Branch::create(['name' => 'London CBC', 'country' => 'GB', 'currency_code' => 'EUR', 'tax_regime' => 'vat', 'vat_pct' => 20, 'is_active' => true]);
        $member = $this->member('CBC1', null, $london->id);
        Bond::create([
            'member_id' => $member->id, 'plan_id' => $this->plan->id, 'bond_date' => now(),
            'value' => 100000, 'cbc_value' => 1000, 'cbc_count' => 1, 'cbc_issued' => 0, 'status' => 'active',
        ]);

        app(CommissionService::class)->issueCbc();
        $entry = CbcEntry::where('member_id', $member->id)->firstOrFail();
        $this->assertEquals('EUR', $entry->currency_code);
        $this->assertEquals(100.0, (float) $entry->fx_rate);   // frozen at issue: 1 EUR = ₹100

        // The rate moves AFTER issue (1 EUR = ₹50). The payout must ignore it.
        Currency::where('code', 'EUR')->update(['rate_to_base' => 0.02]);

        app(CommissionApprovalService::class)->approve($entry->fresh());
        $wallet = MemberWallet::where('member_id', $member->id)->firstOrFail();
        // ₹1,000 at the FROZEN ₹100/EUR = €10 → 40% EPIN €4 + 60% coupon €6 (not €20 at the new rate).
        $this->assertEquals('EUR', $wallet->currency_code);
        $this->assertEquals(4.0, (float) $wallet->epin_balance);
        $this->assertEquals(6.0, (float) $wallet->coupon_balance);
        $this->assertEquals(10.0, (float) $wallet->earning_total);
    }
}
