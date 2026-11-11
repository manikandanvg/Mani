<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AreaDistributorService;
use App\Services\BranchOrderService;
use App\Services\SalesCancelService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Complaints 2026-11-11:
 *  (1) billing the Area Distributor scheme opens the dealer's branch + login + stock at once;
 *  (2) cancelling that bill closes them again;
 *  (3) a dealer approves an order only as a matrix-valid supplier, never a cash-stock order.
 */
class Complaints20261111Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function areaPlan(): Plan
    {
        return Plan::create([
            'code' => 'PAD', 'name' => ['en' => 'Area Distributor'], 'plan_type' => 2, 'type' => 'digital', 'hid' => 8,
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 0,
            'is_active' => true, 'available_to' => 'all', 'is_contract' => true, 'is_redeem' => false,
        ]);
    }

    protected function bill(Branch $branch, Plan $plan, array $customer): array
    {
        $cp = CatalogProduct::firstOrCreate(['code' => 'G1AD'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::updateOrCreate(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'order_line_id' => 0], ['quantity' => 50]);
        $svc = app(SalesService::class);
        $invoice = $svc->generateInvoice([
            'branch_id' => $branch->id, 'plan_id' => $plan->id, 'gold_rate' => 7000,
            'customer' => $customer,
            'cart' => [[
                'catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 2, 'qty' => 2, 'rate' => 7000,
                'net_total' => 14000, 'grand_total' => 14420,
            ]],
        ]);

        return [$invoice, $svc->openedDealer, $cp];
    }

    public function test_area_distributor_bill_opens_branch_login_and_stock_and_cancel_closes_them(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'LORD JEWELLER', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $hq->update(['address' => '62K Gandhi Road', 'city' => 'Sivakasi', 'pincode' => '626123', 'gst_no' => '33AREPB3962B3ZD']);
        $plan = $this->areaPlan();

        [$invoice, $opened, $cp] = $this->bill($hq, $plan, ['name' => 'Area Cust', 'phone' => '9000011111', 'pan' => 'ABCDE1234F']);

        $this->assertNotNull($opened);
        $this->assertTrue($opened['created']);
        $member = Member::findOrFail($invoice->customer_member_id);

        $branch = Branch::findOrFail($opened['branch_id']);
        $this->assertSame('area_dealer', $branch->level);
        $this->assertSame('dealer', $branch->kind);
        $this->assertNull($branch->source_branch_id);
        $this->assertSame($hq->name, $branch->name);
        $this->assertSame('626123', $branch->pincode);
        $this->assertSame('33AREPB3962B3ZD', $branch->gst_no);
        $this->assertSame('Area Cust / ' . $member->member_code, $branch->incharge);
        $this->assertEquals($invoice->id, $branch->opened_invoice_id);

        $user = User::findOrFail($opened['user_id']);
        $this->assertSame(strtolower($member->member_code) . '@lordicl.com', $user->email);
        $this->assertSame($member->member_code, $user->member_code);
        $this->assertSame($branch->id, (int) $user->branch_id);
        $this->assertTrue($user->isDistributor());
        $this->assertTrue(Hash::check($opened['password'], $user->password));
        $this->assertEquals(14420, (float) $user->invested);

        // the billed pieces left the billing branch and became the new branch's stock
        $this->assertEquals(48, (float) Stock::where('branch_id', $hq->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertEquals(2, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertTrue(StockMovement::where('branch_id', $branch->id)->where('ref_type', AreaDistributorService::STOCK_REF)->where('ref_id', $invoice->id)->exists());

        // a repeat bill opens nothing new — the items join the same branch
        [$second, $again] = $this->bill($hq, $plan, ['member_id' => $member->id]);
        $this->assertFalse($again['created']);
        $this->assertNull($again['password']);
        $this->assertSame($branch->id, $again['branch_id']);
        $this->assertSame(1, Branch::where('level', 'area_dealer')->count());
        $this->assertEquals(4, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));

        // cancelling the repeat bill takes only its stock back; the branch stays open
        app(SalesCancelService::class)->cancel($second, null, 'test');
        $this->assertEquals(2, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertTrue((bool) $branch->fresh()->is_active);

        // cancelling the opening bill closes the branch + login and returns the stock
        app(SalesCancelService::class)->cancel($invoice, null, 'billed by accident');
        $this->assertEquals(0, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        // (the helper re-seeded HQ to 50 before the 2nd bill: 50 − 2 + 2 + 2)
        $this->assertEquals(52, (float) Stock::where('branch_id', $hq->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertFalse((bool) $branch->fresh()->is_active);
        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_cancel_is_refused_once_the_area_distributor_used_the_stock(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'LORD JEWELLER', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        [$invoice, $opened, $cp] = $this->bill($hq, $this->areaPlan(), ['name' => 'Area Two', 'phone' => '9000022222', 'pan' => 'ABCDE1234G']);

        Stock::where('branch_id', $opened['branch_id'])->where('catalog_product_id', $cp->id)->update(['quantity' => 1]);

        $this->assertStringContainsString('already used the stock', (string) app(SalesCancelService::class)->blocker($invoice));
        $this->expectException(HttpException::class);
        app(SalesCancelService::class)->cancel($invoice, null, 'x');
    }

    public function test_other_schemes_open_nothing(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'LORD JEWELLER', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $plan = $this->areaPlan();
        $plan->update(['hid' => null]);
        $before = Branch::count();

        [, $opened] = $this->bill($hq, $plan, ['name' => 'Plain Cust', 'phone' => '9000033333', 'pan' => 'ABCDE1234H']);

        $this->assertNull($opened);
        $this->assertSame($before, Branch::count());
    }

    // ---------------------------------------------------------------- (3) order approval

    protected function dealerLogin(Branch $branch, string $email): User
    {
        Role::findOrCreate('distributor', 'web');
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $u->assignRole('distributor');

        return $u;
    }

    protected function order(Branch $buyer, CatalogProduct $cp, float $weight): BranchOrderRequest
    {
        return app(BranchOrderService::class)->submit([
            'branch_id' => $buyer->id, 'requested_by' => null, 'payment_type' => 'cash',
            'lines' => [['catalog_product_id' => $cp->id, 'weight' => $weight]],
        ]);
    }

    public function test_urn_is_mandatory_and_one_payment_can_be_split_only_by_the_same_branch_up_to_the_amount_paid(): void
    {
        $buyer = Branch::create(['name' => 'Urn Buyer', 'country' => 'IN', 'level' => 'area_dealer', 'is_active' => true]);
        $other = Branch::create(['name' => 'Other Buyer', 'country' => 'IN', 'level' => 'area_dealer', 'is_active' => true]);
        $cash = CatalogProduct::firstOrCreate(['code' => 'CASHU'], ['name' => ['en' => 'Cash stock'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);
        $svc = app(BranchOrderService::class);
        $submit = fn (array $extra, float $amount = 40000, ?Branch $b = null) => $svc->submit($extra + [
            'branch_id' => ($b ?? $buyer)->id, 'requested_by' => null, 'payment_type' => 'online', 'urn_required' => true,
            'lines' => [['catalog_product_id' => $cash->id, 'weight' => $amount]],
        ]);
        $refused = function (\Closure $try, string $needle) {
            try {
                $try();
                $this->fail('order accepted, expected: ' . $needle);
            } catch (HttpException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        };

        $refused(fn () => $submit([]), 'Enter the URN number');
        $refused(fn () => $submit(['urn_no' => 'UTR 1']), 'Enter the amount paid');
        $refused(fn () => $submit(['urn_no' => 'UTR 1', 'urn_amount' => 30000]), 'does not cover this order');

        // ₹1,00,000 paid once, ordered as 40,000 + 60,000 because of the order limit
        $first = $submit(['urn_no' => '  utr 4455 6677 ', 'urn_amount' => 100000]);
        $this->assertSame('UTR 4455 6677', $first->urn_no);
        $this->assertEquals(100000, (float) $first->urn_amount);

        $refused(fn () => $submit(['urn_no' => 'UTR 4455 6677', 'urn_amount' => 100000], 40000, $other), 'another branch');
        $refused(fn () => $submit(['urn_no' => 'utr 4455 6677', 'urn_amount' => 150000], 60000), 'enter the same amount paid');
        $refused(fn () => $submit(['urn_no' => 'utr 4455 6677', 'urn_amount' => 100000], 60001.5), 'only ₹60,000');

        $second = $submit(['urn_no' => 'utr  4455 6677', 'urn_amount' => 100000], 60000);
        $this->assertStringContainsString('SHARED with ' . $first->request_no, (string) $second->fresh()->urnNote());
        $refused(fn () => $submit(['urn_no' => 'UTR 4455 6677', 'urn_amount' => 100000], 10), 'only ₹0');

        // approved orders still count; a rejected one frees its share
        $svc->approve($first);
        $svc->reject($second);
        $this->assertSame('pending', $submit(['urn_no' => 'UTR 4455 6677', 'urn_amount' => 100000], 60000)->status);

        // a Digi Cash (wallet) payment has no bank reference
        $buyer->update(['digi_cash_balance' => 100000]);
        $this->assertNull($submit(['payment_type' => 'digi_cash'], 500)->urn_no);
    }

    public function test_cancel_can_delete_the_member_profile_when_the_admin_ticks_it(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'LORD JEWELLER', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $plan = $this->areaPlan();
        $cancel = app(SalesCancelService::class);

        // kept by default
        [$keep] = $this->bill($hq, $plan, ['name' => 'Keep Me', 'phone' => '9000044444', 'pan' => 'ABCDE1234J']);
        $cancel->cancel($keep, null, 'wrong scheme');
        $this->assertNotNull(Member::find($keep->customer_member_id));

        // ticked: profile + the dealer login the bill opened go with the bill
        [$gone, $opened] = $this->bill($hq, $plan, ['name' => 'Wrong Person', 'phone' => '9000055555', 'pan' => 'ABCDE1234K']);
        $this->assertNull($cancel->memberDeleteBlocker($gone));
        $cancel->cancel($gone, null, 'enrolled by mistake', true);
        $this->assertNull(Member::find($gone->customer_member_id));
        $this->assertNotNull(Member::withTrashed()->find($gone->customer_member_id));
        $this->assertSame('inactive', User::find($opened['user_id'])->status);
        $this->assertSame('cancelled', $gone->fresh()->status);

        // a member with another standing bill cannot be deleted — and the bill is not cancelled either
        [$one] = $this->bill($hq, $plan, ['name' => 'Two Bills', 'phone' => '9000066666', 'pan' => 'ABCDE1234L']);
        [$two] = $this->bill($hq, $plan, ['member_id' => $one->customer_member_id]);
        $this->assertStringContainsString('other bills', (string) $cancel->memberDeleteBlocker($two));
        try {
            $cancel->cancel($two, null, 'x', true);
            $this->fail('member with another bill was deleted');
        } catch (HttpException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
        $this->assertNotSame('cancelled', $two->fresh()->status);
        $this->assertNotNull(Member::find($one->customer_member_id));
    }

    public function test_hq_only_sourcing_keeps_every_branch_on_head_office(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $taluk = Branch::create(['name' => 'Taluka L', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $buyer = Branch::create(['name' => 'Buyer L', 'country' => 'IN', 'level' => 'area_dealer', 'source_branch_id' => $taluk->id, 'is_active' => true]);
        $talukUser = $this->dealerLogin($taluk, 'taluk.lock@test.local');
        $gold = CatalogProduct::firstOrCreate(['code' => 'G1LK'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $taluk->id, 'catalog_product_id' => $gold->id, 'order_line_id' => 0, 'quantity' => 10]);
        $this->assertTrue($taluk->maySupply($buyer));   // switch off (tests): the board matrix applies

        config(['features.hq_only_sourcing' => true]);

        $this->assertFalse($taluk->maySupply($buyer));
        $this->assertNull($buyer->fresh()->orderSupplier());
        $order = $this->order($buyer, $gold, 2);
        $this->assertFalse($order->decidableBy($talukUser));
        app(BranchOrderService::class)->approve($order, User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))->firstOrFail()->id);
        $this->assertEquals(10, (float) Stock::where('branch_id', $taluk->id)->where('catalog_product_id', $gold->id)->value('quantity'));

        // nothing can save a dealer as supplier; Head Office is fine
        $new = Branch::create(['name' => 'New L', 'country' => 'IN', 'level' => 'area_dealer', 'source_branch_id' => $taluk->id, 'is_active' => true]);
        $this->assertNull($new->fresh()->source_branch_id);
        $new->update(['source_branch_id' => $hq->id]);
        $this->assertEquals($hq->id, $new->fresh()->source_branch_id);

        // the reset command clears the old dealer links and keeps a backup
        $this->artisan('branches:reset-sources-to-hq', ['--apply' => true])->assertSuccessful();
        $this->assertNull($buyer->fresh()->source_branch_id);
        $this->assertEquals($hq->id, $new->fresh()->source_branch_id);
        foreach (glob(storage_path('app/branch-sources-backup-*.csv')) as $f) {
            if (filemtime($f) >= time() - 60 && str_contains((string) file_get_contents($f), 'Buyer L')) {
                @unlink($f);
            }
        }
    }

    public function test_dealer_never_approves_a_cash_stock_order_and_only_a_valid_supplier_approves_goods(): void
    {
        $hq = Branch::where('level', 'hq')->first() ?? Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $taluk = Branch::create(['name' => 'Taluka', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $sponsor = Branch::create(['name' => 'Sponsor Area', 'country' => 'IN', 'level' => 'area_dealer', 'is_active' => true]);
        $buyer = Branch::create(['name' => 'Buyer Area', 'country' => 'IN', 'level' => 'area_dealer', 'source_branch_id' => $taluk->id, 'is_active' => true]);
        $orphan = Branch::create(['name' => 'Sponsored Area', 'country' => 'IN', 'level' => 'area_dealer', 'source_branch_id' => $sponsor->id, 'is_active' => true]);

        $talukUser = $this->dealerLogin($taluk, 'taluk@test.local');
        $sponsorUser = $this->dealerLogin($sponsor, 'sponsor@test.local');
        $admin = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))->firstOrFail();

        $cash = CatalogProduct::firstOrCreate(['code' => 'CASHT'], ['name' => ['en' => 'Cash stock'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);
        $gold = CatalogProduct::firstOrCreate(['code' => 'G1OR'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $taluk->id, 'catalog_product_id' => $gold->id, 'order_line_id' => 0, 'quantity' => 10]);
        Stock::create(['branch_id' => $sponsor->id, 'catalog_product_id' => $gold->id, 'order_line_id' => 0, 'quantity' => 10]);

        $this->assertTrue($taluk->maySupply($buyer));
        $this->assertFalse($sponsor->maySupply($orphan));   // nobody buys from an Area Distributor
        $this->assertNull($orphan->orderSupplier());

        // cash stock: Head Office only, even for the valid supplier
        $cashOrder = $this->order($buyer, $cash, 50000);
        $this->assertTrue($cashOrder->hasCashLine());
        $this->assertFalse($cashOrder->decidableBy($talukUser));
        $this->assertTrue($cashOrder->decidableBy($admin));
        try {
            app(BranchOrderService::class)->approve($cashOrder, $talukUser->id);
            $this->fail('a dealer approved a cash-stock order');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Head Office only', $e->getMessage());
        }
        $this->assertSame('pending', $cashOrder->fresh()->status);
        app(BranchOrderService::class)->approve($cashOrder, $admin->id);
        $this->assertSame('approved', $cashOrder->fresh()->status);
        $this->assertEquals(50000, (float) Stock::where('branch_id', $buyer->id)->where('catalog_product_id', $cash->id)->value('quantity'));

        // goods: the matrix-valid supplier approves and ships from its own stock
        $goods = $this->order($buyer, $gold, 2);
        $this->assertTrue($goods->decidableBy($talukUser));
        $this->assertFalse($goods->decidableBy($sponsorUser));
        app(BranchOrderService::class)->approve($goods, $talukUser->id);
        $this->assertEquals(8, (float) Stock::where('branch_id', $taluk->id)->where('catalog_product_id', $gold->id)->value('quantity'));

        // a sponsor recorded as "supplier" can neither approve nor is its stock touched
        $sponsored = $this->order($orphan, $gold, 3);
        $this->assertFalse($sponsored->decidableBy($sponsorUser));
        try {
            app(BranchOrderService::class)->approve($sponsored, $sponsorUser->id);
            $this->fail('an Area Distributor sponsor approved an order');
        } catch (HttpException $e) {
            $this->assertStringContainsString('decided by Head Office', $e->getMessage());
        }
        app(BranchOrderService::class)->approve($sponsored, $admin->id);
        $this->assertEquals(10, (float) Stock::where('branch_id', $sponsor->id)->where('catalog_product_id', $gold->id)->value('quantity'));
        $this->assertEquals(3, (float) Stock::where('branch_id', $orphan->id)->where('catalog_product_id', $gold->id)->value('quantity'));
    }
}
