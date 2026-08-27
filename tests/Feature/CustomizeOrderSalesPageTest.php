<?php

namespace Tests\Feature;

use App\Filament\Pages\Sales;
use App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders;
use App\Filament\Resources\StockResource\Pages\ListStock;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Models\Stock;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\CustomizeOrderService;
use App\Support\CustomizeOrderPricing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Screens of the customized-order travel (board 2026-08-27): Order Requests shows the
 * right buttons per hop, the Stock screen shows the labelled piece with "Forward", and
 * the Sales page picker fills the customer + locked cart at the frozen price.
 */
class CustomizeOrderSalesPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $dealer;

    protected Branch $hq;

    protected Branch $dealerBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->dealer = User::where('email', 'distributor@lordicl.com')->firstOrFail();
        $this->hq = Branch::where('level', 'hq')->firstOrFail();
        $this->dealerBranch = Branch::findOrFail($this->dealer->branch_id);
        $this->dealerBranch->update(['source_branch_id' => $this->hq->id, 'digi_cash_balance' => 100000]);
        $this->get('/admin/login');

        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        CustomizeOrderPricing::save(['gold_margin_per_g' => 200, 'silver_margin_per_g' => 5, 'gst_pct' => 3]);
        ChargeBracket::query()->delete();
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 50, 'making_pct' => 10, 'wastage_pct' => 0]);
        Plan::create(['code' => 'P206T', 'name' => ['en' => 'G10 Gold'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0, 'allocation_bv' => 100,
            'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [], 'billing_margin' => 0, 'is_active' => true]);
    }

    /** Dealer (source = HQ) orders a 2 g gold chain for a new customer, paid from the wallet: 11,783.20. */
    protected function order()
    {
        return app(BranchOrderService::class)->submitCustomize([
            'branch_id' => $this->dealerBranch->id, 'requested_by' => $this->dealer->id,
            'customer_mode' => 'new', 'customer' => ['name' => 'Walk-in Priya', 'phone' => '9876543210', 'city' => 'Madurai'],
            'lines' => [['material' => 'gold', 'description' => 'Gold chain', 'grams' => 2]],
            'pay_cash' => 0, 'pay_wallet' => 11783.20,
        ]);
    }

    public function test_order_requests_offers_the_right_buttons_at_each_hop(): void
    {
        $order = $this->order();
        $this->assertSame($this->hq->id, (int) $order->current_branch_id);   // straight to HQ

        // HQ: accept / reject, never the stock-order approve; dealer (requestor) has no decision.
        Livewire::actingAs($this->admin)->test(ListBranchOrders::class)
            ->assertTableActionVisible('accept', $order)
            ->assertTableActionVisible('reject_custom', $order)
            ->assertTableActionHidden('approve', $order)
            ->assertTableActionHidden('forward', $order)
            ->assertTableActionHidden('deliver', $order);
        Livewire::actingAs($this->dealer)->test(ListBranchOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableActionHidden('accept', $order)
            ->assertTableActionHidden('forward', $order);

        // Accept through the table action modal, then Delivery appears for HQ.
        Livewire::actingAs($this->admin)->test(ListBranchOrders::class)
            ->callTableAction('accept', $order, ['delivery_date' => now()->addDays(7)->toDateString(), 'quote_extra' => 250, 'note' => 'ok'])
            ->assertHasNoTableActionErrors();
        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertEquals(250.0, (float) $order->quote_extra);

        Livewire::actingAs($this->admin)->test(ListBranchOrders::class)
            ->assertTableActionVisible('deliver', $order)
            ->callTableAction('deliver', $order);
        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame($this->dealerBranch->id, (int) $order->current_branch_id);

        // The View popup carries the road map.
        Livewire::actingAs($this->dealer)->test(ListBranchOrders::class)
            ->mountTableAction('view', $order)
            ->assertSee('Road map')
            ->assertSee('Accepted by Head Office')
            ->assertSee('Goods sent down the chain');
    }

    public function test_stock_screen_shows_the_labelled_piece_and_hq_can_forward_it(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $svc->accept($this->order(), ['delivery_date' => '2026-09-10'], $this->admin);
        $line = $order->lines->first();
        $row = Stock::where('order_line_id', $line->id)->firstOrFail();
        $this->assertSame($this->hq->id, (int) $row->branch_id);

        Livewire::actingAs($this->admin)->test(ListStock::class)
            ->filterTable('branch_id', $this->hq->id)
            ->assertCanSeeTableRecords([$row])
            ->assertSee('Gold 2 g Gold chain (' . $order->request_no . ')')
            ->assertTableActionVisible('forward', $row)
            ->assertTableActionHidden('adjust', $row)
            ->callTableAction('forward', $row);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertEquals(1.0, (float) Stock::where('branch_id', $this->dealerBranch->id)->where('order_line_id', $line->id)->value('quantity'));
    }

    public function test_sales_page_picker_fills_customer_and_locked_cart_at_frozen_price(): void
    {
        $svc = app(CustomizeOrderService::class);
        $order = $svc->deliver($svc->accept($this->order(), ['delivery_date' => '2026-09-10', 'quote_extra' => 250], $this->admin), $this->admin);
        $line = $order->lines->first();
        $key = $order->id . ':gold';

        $page = Livewire::actingAs($this->dealer)->test(Sales::class)
            ->assertFormFieldExists('custom_order')
            ->fillForm(['custom_order' => $key])
            ->assertSee('extra quote ₹250')
            ->assertFormSet([
                'mode' => 'new',
                'customer.name' => 'Walk-in Priya',
                'customer.phone' => '9876543210',
                'customer.city' => 'Madurai',
            ]);

        $cart = $page->get('data.cart');
        $this->assertCount(1, $cart);
        $first = array_values($cart)[0];
        $this->assertSame($line->id, (int) $first['order_line_id']);
        $this->assertSame(CustomizeOrderService::customProduct('gold')->id, (int) $first['catalog_product_id']);
        $this->assertEquals(2.0, (float) $first['weight']);
        $this->assertEquals(5200.0, (float) $first['rate']);              // frozen live + margin
        $this->assertEquals(10.0, (float) $first['making_charge_pct']);   // charge bracket frozen on the line

        // Normal billing never lists the custom piece.
        $page->fillForm(['custom_order' => null]);
        $this->assertSame([], $page->get('data.cart'));
        $this->assertArrayNotHasKey(CustomizeOrderService::customProduct('gold')->id, (array) $this->invokeStockOptions($this->dealerBranch->id));
    }

    protected function invokeStockOptions(int $branchId): array
    {
        $m = new \ReflectionMethod(Sales::class, 'stockOptions');
        $m->setAccessible(true);

        return $m->invoke(null, $branchId, []);
    }
}
