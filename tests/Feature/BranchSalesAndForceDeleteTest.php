<?php

namespace Tests\Feature;

use App\Filament\Pages\BranchSales;
use App\Filament\Pages\OrderForm;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\RedeemableQr;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\CommissionApprovalService;
use App\Services\MemberForceDeleteService;
use App\Services\SalesCancelService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * User 2026-11-22:
 *  1. Branch Sales — Head Office allocates stock straight to any branch; on the Order Form
 *     Head Office picks the branch the order is for.
 *  2. Force delete of a member — every value issued is shown, what is still in a wallet is
 *     taken back, what was withdrawn / redeemed is charged to the issuing branch's cash stock
 *     (below zero allowed); a member with a team is refused.
 */
class BranchSalesAndForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@lordicl.com')->firstOrFail();
    }

    protected function dealerLogin(Branch $branch): User
    {
        $user = User::create(['name' => 'Dealer', 'email' => 'dealer' . $branch->id . '@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $user->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function gold(): CatalogProduct
    {
        return CatalogProduct::firstOrCreate(['code' => 'G1T'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
    }

    protected function cash(): CatalogProduct
    {
        return CatalogProduct::firstOrCreate(['code' => 'CASHT'], ['name' => ['en' => 'Cash'], 'material' => 'cash', 'is_active' => true]);
    }

    protected function plan(): Plan
    {
        return Plan::firstOrCreate(['code' => 'PFD'], [
            'name' => ['en' => 'Force delete plan'], 'plan_type' => 2, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 10, 'cbc_count' => 3, 'ic_schedule' => [5, 3], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 2,
            'is_active' => true, 'available_to' => 'all', 'is_contract' => true, 'is_redeem' => true,
        ]);
    }

    protected function bill(Branch $branch, ?Member $upline = null, ?Member $existing = null): SalesInvoice
    {
        $cp = $this->gold();
        Stock::updateOrCreate(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'order_line_id' => 0], ['quantity' => 50]);

        return app(SalesService::class)->generateInvoice(array_filter([
            'branch_id' => $branch->id,
            'plan_id' => $this->plan()->id,
            'gold_rate' => 7000,
            'upline_code' => $upline?->member_code,
            'referrer_code' => $upline?->member_code,
            'customer' => $existing ? ['member_id' => $existing->id] : ['name' => 'Force Cust', 'phone' => '9' . random_int(100000000, 999999999), 'pan' => 'ABCDE1234F'],
            'cart' => [[
                'catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 2, 'qty' => 2, 'rate' => 7000,
                'net_total' => 14000, 'grand_total' => 14420,
            ]],
        ], fn ($v) => $v !== null));
    }

    // ───────────────────────── 1. Branch Sales ─────────────────────────

    public function test_head_office_allocates_stock_directly_to_a_branch(): void
    {
        $dealer = Branch::create(['name' => 'Dealer BS', 'country' => 'IN', 'is_active' => true]);
        $cp = $this->gold();
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 90, 'effective_at' => now(), 'source' => 'manual']);
        $this->actingAs($this->admin());

        $order = app(BranchOrderService::class)->allocate([
            'branch_id' => $dealer->id,
            'payment_type' => 'cash',
            'payment_remarks' => 'Opening allocation',
            'lines' => [['catalog_product_id' => $cp->id, 'weight' => 5]],
        ]);

        $this->assertSame(BranchOrderService::SOURCE_BRANCH_SALES, $order->source);
        $this->assertSame('approved', $order->status);
        $this->assertSame($this->admin()->id, (int) $order->approved_by);
        $this->assertNull($order->urn_no, 'no URN is demanded from Head Office');
        // 5 g of a 1 g piece = 5 pieces, in the branch at once
        $this->assertSame(5.0, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertTrue(StockMovement::where('branch_id', $dealer->id)->where('ref_type', 'branch_order')->where('ref_id', $order->id)->exists());
        $this->assertSame('Branch Sales (HQ)', $order->typeLabel());
    }

    public function test_a_dealer_login_can_never_run_branch_sales(): void
    {
        $dealer = Branch::create(['name' => 'Dealer BS', 'country' => 'IN', 'is_active' => true]);
        $other = Branch::create(['name' => 'Dealer Other', 'country' => 'IN', 'is_active' => true]);
        $cp = $this->gold();
        $this->actingAs($this->dealerLogin($dealer));

        $this->assertFalse(BranchSales::canAccess());
        try {
            app(BranchOrderService::class)->allocate(['branch_id' => $other->id, 'lines' => [['catalog_product_id' => $cp->id, 'weight' => 5]]]);
            $this->fail('A dealer allocated stock.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, BranchOrderRequest::count());
    }

    public function test_branch_sales_page_allocates_and_order_form_lets_head_office_pick_the_branch(): void
    {
        $dealer = Branch::create(['name' => 'Dealer BS', 'country' => 'IN', 'is_active' => true]);
        $cp = $this->gold();
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 90, 'effective_at' => now(), 'source' => 'manual']);
        $this->actingAs($this->admin());
        $this->get('/admin/login');

        $this->assertTrue(BranchSales::canAccess());
        \Livewire\Livewire::test(BranchSales::class)
            ->assertFormFieldExists('branch_id')
            ->fillForm(['branch_id' => $dealer->id, 'payment_type' => 'cash',
                'lines' => [['catalog_product_id' => $cp->id, 'qty' => 3, 'unit_weight' => 1, 'weight' => 3]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $allocated = BranchOrderRequest::where('source', BranchOrderService::SOURCE_BRANCH_SALES)->firstOrFail();
        $this->assertSame((int) $dealer->id, (int) $allocated->branch_id);
        $this->assertSame('approved', $allocated->status);
        $this->assertSame(3.0, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cp->id)->value('quantity'));

        // Order Form: Head Office places a (pending) order on behalf of the branch it picks
        \Livewire\Livewire::test(OrderForm::class)
            ->assertFormFieldExists('branch_id')
            ->fillForm(['branch_id' => $dealer->id, 'payment_type' => 'cash', 'urn_no' => 'URN12345', 'urn_amount' => 50000,
                'lines' => [['catalog_product_id' => $cp->id, 'qty' => 2, 'unit_weight' => 1, 'weight' => 2]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $pending = BranchOrderRequest::where('source', BranchOrderService::SOURCE_ORDER_FORM)->firstOrFail();
        $this->assertSame((int) $dealer->id, (int) $pending->branch_id);
        $this->assertSame('pending', $pending->status);
        $this->assertSame(3.0, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cp->id)->value('quantity'), 'a pending order adds nothing yet');
    }

    public function test_a_dealer_order_form_always_orders_for_its_own_branch(): void
    {
        $dealer = Branch::create(['name' => 'Dealer BS', 'country' => 'IN', 'is_active' => true]);
        $other = Branch::create(['name' => 'Dealer Other', 'country' => 'IN', 'is_active' => true]);
        $cp = $this->gold();
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 90, 'effective_at' => now(), 'source' => 'manual']);
        $this->actingAs($this->dealerLogin($dealer));
        $this->get('/admin/login');
        \Illuminate\Support\Carbon::setTestNow(now()->timezone('Asia/Kolkata')->setTime(11, 0));

        \Livewire\Livewire::test(OrderForm::class)
            ->set('data.branch_id', $other->id)   // a tampered form state
            ->fillForm(['payment_type' => 'cash', 'urn_no' => 'URN77777', 'urn_amount' => 50000,
                'lines' => [['catalog_product_id' => $cp->id, 'qty' => 3, 'unit_weight' => 1, 'weight' => 3]]])
            ->call('save');
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(0, BranchOrderRequest::where('branch_id', $other->id)->count());
        $this->assertSame(1, BranchOrderRequest::where('branch_id', $dealer->id)->count());
    }

    // ───────────────────────── 2. Force delete ─────────────────────────

    /** A member with two bills: paid coupon + paid incentive (partly withdrawn) + a redeemed QR. */
    protected function scenario(): array
    {
        $dealer = Branch::create(['name' => 'Dealer FD', 'country' => 'IN', 'is_active' => true]);
        $cashCp = $this->cash();
        Stock::create(['branch_id' => $dealer->id, 'catalog_product_id' => $cashCp->id, 'order_line_id' => 0, 'quantity' => 1000]);
        $upline = Member::firstOrFail();
        Bond::create(['member_id' => $upline->id, 'plan_id' => $this->plan()->id, 'bond_date' => '2026-01-01', 'value' => 500000, 'status' => 'active']);

        $first = $this->bill($dealer, $upline);
        $member = $first->customer;
        $second = $this->bill($dealer, $upline, $member);
        $this->assertSame((int) $member->id, (int) $second->customer_member_id);

        $bond1 = Bond::where('invoice_no', $first->invoice_no)->firstOrFail();
        $approval = app(CommissionApprovalService::class);
        // one coupon of bill 1 paid into the member's own wallet
        $coupon = CbcEntry::where('bond_id', $bond1->id)->orderBy('id')->firstOrFail();
        $this->assertTrue($approval->approve($coupon));
        // the incentive of bill 1 paid to the upline — who has since withdrawn all but ₹100
        $ic = CommissionLedger::where('bond_id', $bond1->id)->where('member_id', $upline->id)->orderBy('id')->firstOrFail();
        $this->assertTrue($approval->approve($ic));
        $icNet = (float) $ic->fresh()->net_amount;
        $this->assertGreaterThan(100, $icNet);
        MemberWallet::whereKey($upline->id)->update(['cash_balance' => 100]);
        // the stock QR of bill 2 was redeemed
        $qr = RedeemableQr::where('invoice_no', $second->invoice_no)->firstOrFail();
        $qr->update(['status' => 'redeemed', 'cash_worth' => 14420, 'redeem_branch_id' => $dealer->id, 'redeemed_at' => now()]);

        return compact('dealer', 'cashCp', 'upline', 'member', 'first', 'second', 'coupon', 'icNet');
    }

    public function test_plain_delete_is_refused_but_the_statement_lists_every_value(): void
    {
        ['member' => $member, 'first' => $first, 'second' => $second, 'coupon' => $coupon, 'icNet' => $icNet, 'dealer' => $dealer, 'upline' => $upline] = $this->scenario();

        // the complaint: "has other bills — the profile is still needed"
        $this->assertStringContainsString('has other bills', (string) app(SalesCancelService::class)->memberDeleteBlocker($first));

        $s = app(MemberForceDeleteService::class)->statement($member);
        $this->assertSame([], $s['blockers']);
        $this->assertCount(2, $s['bills']);
        $kinds = array_column($s['rows'], 'kind');
        $this->assertContains('Promotional Incentive (CBC) — PAID', $kinds);
        $this->assertContains('Sales Incentive (IC) — PAID', $kinds);
        $this->assertContains('Billing QR — REDEEMED', $kinds);

        $couponCash = (float) $coupon->fresh()->cash_amount;
        // the coupon cash is still in the member's wallet → taken back; the incentive: ₹100 from
        // the upline's wallet, the withdrawn rest + the redeemed QR fall on the issuing branch
        $this->assertEqualsWithDelta($couponCash + 100, $s['totals']['recover'], 0.01);
        $this->assertEqualsWithDelta(($icNet - 100) + 14420, $s['totals']['charge'], 0.01);
        $this->assertCount(1, $s['charges']);
        $this->assertSame((int) $dealer->id, $s['charges'][0]['branch_id']);
        $this->assertEqualsWithDelta(1000 - (($icNet - 100) + 14420), $s['charges'][0]['after'], 0.01);
        $this->assertSame((int) $upline->id, collect($s['wallet_debits'])->firstWhere('member_id', $upline->id)['member_id']);
    }

    public function test_force_delete_recovers_charges_the_branch_below_zero_and_removes_the_member(): void
    {
        ['member' => $member, 'first' => $first, 'second' => $second, 'icNet' => $icNet, 'dealer' => $dealer, 'upline' => $upline, 'cashCp' => $cashCp] = $this->scenario();
        $teamBefore = (int) $upline->fresh()->downline_count;
        $gbvBefore = (float) $upline->fresh()->gbv;

        \App\Services\Activity\ActivityLogger::$enabled = true;   // the seeder switches it off
        $done = app(MemberForceDeleteService::class)->delete($member, $this->admin()->id, 'Duplicate profile');

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(0, Bond::where('member_id', $member->id)->where('status', '!=', 'cancelled')->count());
        $this->assertSoftDeleted('members', ['id' => $member->id]);
        // wallets: the upline keeps nothing of the reversed incentive, the member's wallet is emptied
        $this->assertSame(0.0, (float) MemberWallet::find($upline->id)->cash_balance);
        $this->assertSame(0.0, (float) MemberWallet::find($member->id)->cash_balance);
        // the issuing branch's cash stock went BELOW ZERO by the withdrawn incentive + the redeemed QR
        $expected = 1000 - (($icNet - 100) + 14420);
        $this->assertLessThan(0, $expected);
        $this->assertEqualsWithDelta($expected, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cashCp->id)->value('quantity'), 0.01);
        $this->assertEqualsWithDelta(-(($icNet - 100) + 14420),
            (float) StockMovement::where('ref_type', MemberForceDeleteService::MOVEMENT_REF)->where('ref_id', $member->id)->sum('qty_change'), 0.01);
        // unpaid lines are gone, paid rows stay on record
        $this->assertSame(0, CbcEntry::where('member_id', $member->id)->where('status', 'pending')->count());
        $this->assertSame(1, CbcEntry::where('member_id', $member->id)->where('status', 'paid')->count());
        // network: both bills leave the upline's GBV, the member leaves the team count ONCE
        $this->assertEqualsWithDelta($gbvBefore - 2 * 14420, (float) $upline->fresh()->gbv, 0.01);
        $this->assertSame($teamBefore - 1, (int) $upline->fresh()->downline_count);
        $this->assertCount(2, $done['bills']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'force_deleted']);
    }

    public function test_an_unpaid_withdrawal_request_is_cancelled_and_its_money_taken_back_from_the_wallet(): void
    {
        ['member' => $member, 'icNet' => $icNet, 'dealer' => $dealer, 'coupon' => $coupon, 'cashCp' => $cashCp] = $this->scenario();
        $couponCash = (float) $coupon->fresh()->cash_amount;
        // the member asked to withdraw the coupon cash; Head Office has not paid the branch yet
        MemberWallet::whereKey($member->id)->update(['cash_balance' => 0, 'withdrawn_total' => $couponCash]);
        $w = \App\Models\WalletWithdrawal::create(['member_id' => $member->id, 'branch_id' => $dealer->id, 'wallet' => 'member_cash', 'amount' => $couponCash, 'status' => 'pending']);

        $svc = app(MemberForceDeleteService::class);
        $s = $svc->statement($member);
        $this->assertSame([], $s['blockers']);
        $this->assertContains('Wallet withdrawal — not paid yet', array_column($s['rows'], 'kind'));
        // the coupon is still recovered from the wallet, not charged to the branch
        $this->assertEqualsWithDelta(($icNet - 100) + 14420, $s['totals']['charge'], 0.01);

        $svc->delete($member, $this->admin()->id, 'Duplicate profile');
        $this->assertSame('cancelled', $w->fresh()->status);
        $this->assertSame(0.0, (float) MemberWallet::find($member->id)->cash_balance);
        $this->assertEqualsWithDelta(1000 - (($icNet - 100) + 14420), (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cashCp->id)->value('quantity'), 0.01);
        $this->assertSoftDeleted('members', ['id' => $member->id]);
    }

    public function test_force_delete_is_refused_while_the_member_has_direct_distributors(): void
    {
        $dealer = Branch::create(['name' => 'Dealer FD', 'country' => 'IN', 'is_active' => true]);
        $this->cash();
        $root = Member::firstOrFail();
        $parent = $this->bill($dealer, $root)->customer;
        $this->bill($dealer, $parent);
        $this->bill($dealer, $parent);

        $svc = app(MemberForceDeleteService::class);
        $why = implode(' ', $svc->blockers($parent));
        $this->assertStringContainsString($parent->member_code . ' has 2 direct distributors', $why);
        $this->assertStringContainsString('remove those 2 first', $why);

        try {
            $svc->delete($parent, $this->admin()->id, 'Try');
            $this->fail('A member with a team was force deleted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertNotSoftDeleted('members', ['id' => $parent->id]);
    }

    public function test_the_sales_invoices_table_offers_force_delete_to_the_super_admin_with_a_confirmation(): void
    {
        ['member' => $member, 'first' => $first, 'dealer' => $dealer] = $this->scenario();
        $this->actingAs($this->admin());
        $this->get('/admin/login');

        $list = \Livewire\Livewire::test(\App\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices::class)
            ->filterTable('branch_id', $dealer->id)
            ->assertTableActionVisible('forceDeleteMember', $first);
        // without the tick nothing happens
        $list->callTableAction('forceDeleteMember', $first, data: ['reason' => 'Duplicate', 'confirmed' => false])
            ->assertHasTableActionErrors(['confirmed']);
        $this->assertNotSoftDeleted('members', ['id' => $member->id]);

        $list->callTableAction('forceDeleteMember', $first, data: ['reason' => 'Duplicate', 'confirmed' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified($member->member_code . ' force deleted');
        $this->assertSoftDeleted('members', ['id' => $member->id]);
    }
}
