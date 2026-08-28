<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOrderEvent;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferMargin;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\CustomizeOrderService;
use App\Services\SalesService;
use App\Support\CustomizeOrderPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Customized-order travel (board 2026-08-27): Taluka → District (forward) → HQ accepts
 * with delivery date / coin pick-up / extra quote → pieces travel back down with transfer
 * margin → Taluka bills G10 at the frozen price (extra quote debited from its wallet) →
 * HQ captures the customer's coins.
 */
class CustomizeOrderTravelTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $district;

    protected Branch $taluk;

    protected User $admin;

    protected User $districtUser;

    protected User $talukUser;

    protected Member $customer;

    protected CatalogProduct $coin;

    protected CatalogProduct $cash;

    protected Plan $g10Gold;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'distributor', 'guard_name' => 'web']);
        $this->hq = Branch::create(['id' => 1, 'name' => 'Head Office', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $this->district = Branch::create(['name' => 'District D', 'country' => 'IN', 'level' => 'district', 'source_branch_id' => $this->hq->id, 'is_active' => true, 'invoice_prefix' => 'D1']);
        $this->taluk = Branch::create(['name' => 'Taluka T', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $this->district->id, 'is_active' => true, 'invoice_prefix' => 'T1', 'digi_cash_balance' => 5000]);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@t.local', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $this->hq->id]);
        $this->districtUser = $this->dealer('d@t.local', $this->district);
        $this->talukUser = $this->dealer('t@t.local', $this->taluk);

        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        $this->customer = Member::create([
            'member_code' => 'LJC777', 'name' => 'Coin Customer', 'phone' => '9000000777', 'joined_on' => now(),
            'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::first()->id,
        ]);

        $this->coin = CatalogProduct::create(['code' => 'COIN100', 'name' => ['en' => 'Gold coin 100 mg'], 'material' => 'gold', 'default_weight' => 0.1, 'gst_pct' => 3, 'is_active' => true]);
        $this->cash = CatalogProduct::create(['code' => 'CASH', 'name' => ['en' => 'Cash'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $this->taluk->id, 'catalog_product_id' => $this->cash->id, 'quantity' => 200000]);

        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        CustomizeOrderPricing::save(['gold_margin_per_g' => 200, 'silver_margin_per_g' => 5, 'gst_pct' => 3, 'coin_product_id' => $this->coin->id]);
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 50, 'making_pct' => 10, 'wastage_pct' => 0]);

        // District earns 2 % when it passes a custom gold piece down; HQ earns nothing.
        StockTransferMargin::create(['catalog_product_id' => CustomizeOrderService::customProduct('gold')->id, 'district_pct' => 2]);

        $this->g10Gold = Plan::create([
            'code' => 'P206', 'name' => ['en' => 'G10 Gold Purchase Plan'], 'plan_type' => 1, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 0,
            'billing_margin' => 0, 'is_redeem' => false, 'is_contract' => false, 'is_active' => true,
        ]);
    }

    protected function dealer(string $email, Branch $branch): User
    {
        $u = User::create(['name' => $branch->name . ' dealer', 'email' => $email, 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $branch->id]);
        $u->assignRole('distributor');

        return $u;
    }

    /** 10 g gold necklace: (5000+200)×10 = 52,000 + 10 % = 57,200 + 3 % GST = 58,916; 5 coins = ₹2,500 credit → pays 56,416 from cash stock. */
    protected function place(int $coins = 5): BranchOrderRequest
    {
        return app(BranchOrderService::class)->submitCustomize([
            'branch_id' => $this->taluk->id, 'requested_by' => $this->talukUser->id,
            'customer_mode' => 'existing', 'member_id' => $this->customer->id,
            'lines' => [['material' => 'gold', 'description' => 'necklace', 'grams' => 10]],
            'coin_qty' => $coins, 'coin_collect_on' => '2026-09-01 10:00',
            'pay_cash' => 58916 - ($coins * 500), 'pay_wallet' => 0,
        ]);
    }

    protected function customStock(int $branchId, int $lineId): float
    {
        return (float) Stock::where('branch_id', $branchId)
            ->where('catalog_product_id', CustomizeOrderService::customProduct('gold')->id)
            ->where('order_line_id', $lineId)->value('quantity');
    }

    public function test_full_road_trip_from_taluka_to_hq_and_back_to_a_g10_bill(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $this->place();
        $line = $order->lines->first();

        // Placed → addressed to the Taluka's supplier (District), road map started.
        $this->assertSame($this->district->id, (int) $order->current_branch_id);
        $this->assertSame([BranchOrderEvent::SUBMITTED], $order->events()->pluck('action')->all());
        $this->assertSame([$this->taluk->id, $this->district->id], $svc->travelPath($order));

        // The old stock-order Approve is refused for customized orders.
        try {
            app(BranchOrderService::class)->approve($order);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('accepted by Head Office', $e->getMessage());
        }

        // District cannot make it → forwards to its supplier (HQ). Taluka user may not forward.
        try {
            $svc->forward($order, $this->talukUser);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $order = $svc->forward($order, $this->districtUser, 'cannot make bespoke pieces here');
        $this->assertSame($this->hq->id, (int) $order->current_branch_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame([$this->taluk->id, $this->district->id, $this->hq->id], $svc->travelPath($order));

        // HQ is the end of the road.
        try {
            $svc->forward($order, $this->admin);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('end of the road', $e->getMessage());
        }

        // Delivery before acceptance is refused.
        try {
            $svc->deliver($order, $this->admin);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('accepted by Head Office before delivery', $e->getMessage());
        }

        // HQ accepts with delivery date, coin pick-up and an extra quote → pieces made into HQ stock.
        $order = $svc->accept($order, ['delivery_date' => '2026-09-10', 'coin_pickup_on' => '2026-09-02 11:00', 'quote_extra' => 1500, 'note' => 'stone work'], $this->admin);
        $this->assertSame('approved', $order->status);
        $this->assertEquals(1500.0, (float) $order->quote_extra);
        $this->assertSame('2026-09-10', $order->delivery_date->toDateString());
        $this->assertSame('2026-09-02 11:00', $order->coin_pickup_on->format('Y-m-d H:i'));
        $this->assertEquals(1.0, $this->customStock($this->hq->id, $line->id));
        $this->assertSame('Gold 10 g necklace (' . $order->request_no . ')', Stock::where('order_line_id', $line->id)->value('label'));

        // HQ → District (no margin for HQ), District → Taluka (district earns 2 % of 10 g × ₹5,200).
        $order = $svc->deliver($order, $this->admin);
        $this->assertSame('in_transit', $order->status);
        $this->assertSame($this->district->id, (int) $order->current_branch_id);
        $this->assertEquals(0.0, $this->customStock($this->hq->id, $line->id));
        $this->assertEquals(1.0, $this->customStock($this->district->id, $line->id));

        try {
            $svc->deliver($order, $this->admin);   // HQ no longer holds it
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $order = $svc->deliver($order, $this->districtUser);
        $this->assertSame('delivered', $order->status);
        $this->assertSame($this->taluk->id, (int) $order->current_branch_id);
        $this->assertNotNull($order->delivered_at);
        $this->assertEquals(1.0, $this->customStock($this->taluk->id, $line->id));
        $hop = StockTransfer::where('source_branch_id', $this->district->id)->firstOrFail();
        $this->assertEquals(52000.0, (float) $hop->transfer_value);   // 10 g × frozen ₹5,200/g
        $this->assertEquals(2.0, (float) $hop->margin_pct);
        $this->assertEquals(1040.0, (float) $hop->margin_amount);
        $this->assertEquals(1040.0, (float) $this->district->fresh()->stock_trans_margin);
        $this->assertEquals(0.0, (float) StockTransfer::where('source_branch_id', $this->hq->id)->value('margin_amount'));

        // Road map so far: submitted, forwarded, accepted, delivered ×2.
        $this->assertSame(
            [BranchOrderEvent::SUBMITTED, BranchOrderEvent::FORWARDED, BranchOrderEvent::ACCEPTED, BranchOrderEvent::DELIVERED, BranchOrderEvent::DELIVERED],
            $order->events()->orderBy('id')->pluck('action')->all()
        );

        // Taluka bills the piece as G10 gold at the FROZEN price; HQ's ₹1,500 quote leaves its wallet.
        $groups = $svc->billableGroups($this->taluk->id);
        $this->assertCount(1, $groups);
        $cart = $svc->cartFor($order, 'gold');
        $this->assertSame($line->id, $cart[0]['order_line_id']);
        $this->assertEquals(5200.0, $cart[0]['rate']);
        $cart[0]['net_total'] = 57200;
        $cart[0]['grand_total'] = 58916;
        $cart[0]['making'] = 5200;
        $cart[0]['wastage'] = 0;

        $invoice = app(SalesService::class)->generateInvoice([
            'branch_id' => $this->taluk->id, 'plan_id' => $this->g10Gold->id, 'created_by' => $this->talukUser->id,
            'custom_order_id' => $order->id,
            'customer' => ['member_id' => $this->customer->id],
            'cart' => $cart,
        ]);
        $this->assertEquals(58916.0, (float) $invoice->grand_total);
        $this->assertSame($this->customer->id, $invoice->customer_member_id);
        $order->refresh();
        $line->refresh();
        $this->assertSame('billed', $order->status);
        $this->assertSame($invoice->id, $line->sales_invoice_id);
        $this->assertNotNull($line->billed_at);
        $this->assertNotNull($order->quote_debited_at);
        $this->assertEquals(3500.0, (float) $this->taluk->fresh()->digi_cash_balance);   // 5,000 − 1,500 quote
        $this->assertEquals(0.0, $this->customStock($this->taluk->id, $line->id));       // piece sold
        $this->assertEmpty($svc->billableGroups($this->taluk->id));
        $this->assertSame(BranchOrderEvent::BILLED, $order->events()->latest('id')->value('action'));

        // HQ staff receive the 5 coins from the Taluka: coin stock moves Taluka → HQ.
        $order = $svc->captureCoins($order, $this->admin, 'handed over by T dealer');
        $this->assertNotNull($order->coin_captured_at);
        $this->assertSame(SalesReturn::STATUS_RELAYED, $order->salesReturn->status);
        $this->assertEquals(5.0, (float) Stock::where('branch_id', $this->hq->id)->where('catalog_product_id', $this->coin->id)->value('quantity'));
        $this->assertEquals(0.0, (float) Stock::where('branch_id', $this->taluk->id)->where('catalog_product_id', $this->coin->id)->value('quantity'));

        try {
            $svc->captureCoins($order, $this->admin);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('already captured', $e->getMessage());
        }
    }

    public function test_intermediate_hop_can_reject_and_the_taluka_is_refunded(): void
    {
        $order = $this->place();
        $this->assertEquals(200000 - 56416, (float) Stock::where('branch_id', $this->taluk->id)->where('catalog_product_id', $this->cash->id)->value('quantity'));

        app(CustomizeOrderService::class)->reject($order, $this->districtUser, 'design not possible');

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertEquals(200000.0, (float) Stock::where('branch_id', $this->taluk->id)->where('catalog_product_id', $this->cash->id)->value('quantity'));
        $this->assertSame(SalesReturn::STATUS_CANCELLED, $order->salesReturn->fresh()->status);
        $this->assertSame(BranchOrderEvent::REJECTED, $order->events()->latest('id')->value('action'));
    }

    public function test_hq_cannot_accept_before_the_order_reaches_it_and_only_hq_accepts(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $this->place(0);

        try {
            $svc->accept($order, ['delivery_date' => '2026-09-10'], $this->admin);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('not reached Head Office', $e->getMessage());
        }
        $order = $svc->forward($order, $this->districtUser);
        try {
            $svc->accept($order, ['delivery_date' => '2026-09-10'], $this->districtUser);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $order = $svc->accept($order, ['delivery_date' => '2026-09-10'], $this->admin);
        $this->assertSame('approved', $order->status);
        $this->assertEquals(0.0, (float) $order->quote_extra);
    }

    public function test_billing_is_refused_when_the_wallet_cannot_cover_hq_extra_quote(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $this->place(0);
        $order = $svc->forward($order, $this->districtUser);
        $order = $svc->accept($order, ['delivery_date' => '2026-09-10', 'quote_extra' => 9000], $this->admin);   // wallet holds 5,000
        $order = $svc->deliver($order, $this->admin);
        $order = $svc->deliver($order, $this->districtUser);
        $line = $order->lines->first();

        $cart = $svc->cartFor($order, 'gold');
        $cart[0] += ['net_total' => 57200, 'grand_total' => 58916, 'making' => 5200, 'wastage' => 0];

        try {
            app(SalesService::class)->generateInvoice([
                'branch_id' => $this->taluk->id, 'plan_id' => $this->g10Gold->id, 'custom_order_id' => $order->id,
                'customer' => ['member_id' => $this->customer->id], 'cart' => $cart,
            ]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('Top up the wallet', $e->getMessage());
        }
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertNull($line->fresh()->billed_at);
        $this->assertEquals(1.0, $this->customStock($this->taluk->id, $line->id));   // rolled back, piece still in stock
        $this->assertEquals(5000.0, (float) $this->taluk->fresh()->digi_cash_balance);
    }

    public function test_wrong_plan_or_branch_cannot_bill_custom_pieces(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $svc->accept($svc->forward($this->place(0), $this->districtUser), ['delivery_date' => '2026-09-10'], $this->admin);
        $order = $svc->deliver($svc->deliver($order, $this->admin), $this->districtUser);
        $cart = $svc->cartFor($order, 'gold');
        $cart[0] += ['net_total' => 57200, 'grand_total' => 58916, 'making' => 5200, 'wastage' => 0];

        $silverPlan = Plan::create(['code' => 'P211', 'name' => ['en' => 'G10 Silver'], 'plan_type' => 4, 'type' => 'silver', 'min_value' => 0, 'allocation_bv' => 100,
            'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [], 'billing_margin' => 0, 'is_active' => true]);
        try {
            app(SalesService::class)->generateInvoice(['branch_id' => $this->taluk->id, 'plan_id' => $silverPlan->id, 'custom_order_id' => $order->id,
                'customer' => ['member_id' => $this->customer->id], 'cart' => $cart]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('G10 gold plan', $e->getMessage());
        }
        try {
            app(SalesService::class)->generateInvoice(['branch_id' => $this->district->id, 'plan_id' => $this->g10Gold->id, 'custom_order_id' => $order->id,
                'customer' => ['member_id' => $this->customer->id], 'cart' => $cart]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('does not belong to this branch', $e->getMessage());
        }
    }

    /** User 2026-08-29: the ordering branch can RECEIVE — pulls the pieces through every remaining hop. */
    public function test_ordering_branch_receives_and_pulls_the_pieces_through_the_hops(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $svc->accept($svc->forward($this->place(0), $this->districtUser), ['delivery_date' => '2026-09-10'], $this->admin);
        $order = $svc->deliver($order, $this->admin);              // HQ → District
        $this->assertSame('in_transit', $order->status);
        $this->assertSame($this->district->id, (int) $order->current_branch_id);
        $line = $order->lines->first();

        // Another dealer cannot pull it.
        try {
            $svc->receive($order, $this->districtUser);
            $this->fail('district must not receive');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $order = $svc->receive($order, $this->talukUser);

        $this->assertSame('delivered', $order->status);
        $this->assertSame($this->taluk->id, (int) $order->current_branch_id);
        $this->assertEquals(1, $this->customStock($this->taluk->id, $line->id));
        $this->assertEquals(0, $this->customStock($this->district->id, $line->id));
        // The pulled hop still logged a delivery and the district still earned its transfer margin row.
        $this->assertSame(2, $order->events()->where('action', 'delivered')->count());

        Livewire::actingAs($this->talukUser)->test(\App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders::class)
            ->assertTableActionHidden('receive', $order);
    }
}
