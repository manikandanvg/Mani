<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Rank;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Services\BranchOrderService;
use App\Services\SalesReturnService;
use App\Support\CustomizeOrderPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Customize Order Form (board 2026-08-26, corrected): live + margin pricing with
 * Charge-Bracket making/wastage and GST, new-vs-existing customer, coins as a sales
 * return that is collected into branch stock and relayed to the supplier, and SPLIT
 * cash-stock + branch-wallet payment that must cover the total before the order proceeds.
 */
class CustomizeOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $branch;

    protected CatalogProduct $coin;

    protected CatalogProduct $cash;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $this->branch = Branch::create(['name' => 'Taluk Dealer', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $this->hq->id, 'is_active' => true]);
        $this->coin = CatalogProduct::create([
            'code' => 'COIN100', 'name' => ['en' => 'Gold coin 100 mg'], 'material' => 'gold', 'default_weight' => 0.1,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'is_active' => true,
        ]);
        $this->cash = CatalogProduct::create(['code' => 'CASH', 'name' => ['en' => 'Cash'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $this->branch->id, 'catalog_product_id' => $this->cash->id, 'quantity' => 100000]);   // ₹1,00,000 cash stock
        $this->branch->update(['digi_cash_balance' => 20000]);                                                              // ₹20,000 wallet
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        CustomizeOrderPricing::save(['gold_margin_per_g' => 200, 'silver_margin_per_g' => 5, 'gst_pct' => 3, 'coin_product_id' => $this->coin->id]);
        // Charge Brackets: gold 0–5 g → 8% + 2%; gold 5–50 g → 10% + 4%; silver any → 5% + 0%
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 5, 'making_pct' => 8, 'wastage_pct' => 2]);
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 5.0001, 'wt_to' => 50, 'making_pct' => 10, 'wastage_pct' => 4]);
        ChargeBracket::create(['material' => 'silver', 'wt_from' => 0, 'wt_to' => 10000, 'making_pct' => 5, 'wastage_pct' => 0]);

        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        $this->member = Member::create([
            'member_code' => 'LJC001', 'name' => 'Coin Holder', 'phone' => '9000000001', 'joined_on' => now(),
            'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id'),
        ]);
    }

    /** 10 g gold: (5000+200)×10 = 52,000 · slab 5–50 g → 14% = 7,280 · net 59,280 · GST 3% = 1,778.40 → 61,058.40 */
    protected function submit(array $overrides = []): BranchOrderRequest
    {
        return app(BranchOrderService::class)->submitCustomize(array_merge([
            'branch_id' => $this->branch->id,
            'customer_mode' => 'existing',
            'member_id' => $this->member->id,
            'lines' => [['material' => 'gold', 'description' => 'Gold chain 22k', 'grams' => 10]],
            'pay_cash' => 61058.40,
            'pay_wallet' => 0,
        ], $overrides));
    }

    public function test_line_is_priced_live_plus_margin_with_charge_bracket_and_gst(): void
    {
        $r = $this->submit();

        $this->assertSame(BranchOrderService::SOURCE_CUSTOMIZE, $r->source);
        $this->assertEquals(59280.0, (float) $r->cross_total);
        $this->assertEquals(1778.4, (float) $r->gst_total);
        $this->assertEquals(61058.4, (float) $r->grand_total);
        $this->assertSame($this->member->id, $r->member_id);
        $this->assertNull($r->customer_details);

        $line = $r->lines->first();
        $this->assertNull($line->catalog_product_id);
        $this->assertSame('Gold chain 22k', $line->description);
        $this->assertEquals(5000.0, (float) $line->rate);
        $this->assertEquals(200.0, (float) $line->margin_per_g);
        $this->assertEquals(5200.0, (float) $line->unit_price);
        $this->assertEquals(10.0, (float) $line->making_charge_pct);
        $this->assertEquals(4.0, (float) $line->wastage_charge_pct);
        $this->assertEquals(61058.4, (float) $line->line_total);
    }

    public function test_the_charge_bracket_follows_the_weight_slab(): void
    {
        // 2 g gold → 0–5 g slab (8% + 2%): 10,400 + 1,040 = 11,440 + 3% = 11,783.20
        $r = $this->submit([
            'lines' => [['material' => 'gold', 'description' => 'Ring', 'grams' => 2]],
            'pay_cash' => 11783.20,
        ]);
        $this->assertEquals(11783.2, (float) $r->grand_total);
        $this->assertEquals(8.0, (float) $r->lines->first()->making_charge_pct);

        // silver 50 g → (100+5)×50 = 5,250 + 5% = 5,512.50 + 3% = 5,677.88
        $s = $this->submit([
            'lines' => [['material' => 'silver', 'description' => 'Silver anklet', 'grams' => 50]],
            'pay_cash' => 5677.88,
        ]);
        $this->assertEquals(5677.88, (float) $s->grand_total);
        $this->assertSame('silver', $s->lines->first()->material);
    }

    public function test_weight_outside_every_bracket_carries_no_charges(): void
    {
        // 80 g gold — no gold slab reaches 80 g → 0% charges: 416,000 + 3% = 428,480
        Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->update(['quantity' => 500000]);
        $r = $this->submit([
            'lines' => [['material' => 'gold', 'description' => 'Bar', 'grams' => 80]],
            'pay_cash' => 428480,
        ]);
        $this->assertEquals(428480.0, (float) $r->grand_total);
        $this->assertEquals(0.0, (float) $r->lines->first()->making_charge_pct);
        $this->assertEquals(0.0, (float) $r->lines->first()->wastage_charge_pct);
    }

    public function test_payment_must_cover_the_total_in_full_before_the_order_proceeds(): void
    {
        try {
            $this->submit(['pay_cash' => 50000]);
            $this->fail('short payment must be refused');
        } catch (HttpException $e) {
            $this->assertStringContainsString('cover the order in full', $e->getMessage());
        }
        $this->assertDatabaseCount('branch_order_requests', 0);
        $this->assertEquals(100000.0, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->value('quantity'));
    }

    public function test_split_payment_charges_cash_stock_and_wallet_and_refunds_both_on_reject(): void
    {
        $r = $this->submit(['pay_cash' => 45000, 'pay_wallet' => 16058.40]);

        $this->assertSame('split', $r->payment_type);
        $this->assertEquals(45000.0, (float) $r->pay_cash);
        $this->assertEquals(16058.4, (float) $r->pay_wallet);
        $this->assertEquals(61058.4, (float) $r->paid_amount);
        $this->assertEquals(55000.0, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->value('quantity'));
        $this->assertEquals(3941.6, (float) $this->branch->fresh()->digi_cash_balance);
        $this->assertDatabaseHas('stock_movements', ['ref_type' => 'branch_order', 'ref_id' => $r->id, 'type' => 'adjustment']);

        app(BranchOrderService::class)->reject($r);

        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertEquals(100000.0, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->cash->id)->value('quantity'));
        $this->assertEquals(20000.0, (float) $this->branch->fresh()->digi_cash_balance);
    }

    public function test_insufficient_cash_stock_or_wallet_is_refused(): void
    {
        Stock::where('branch_id', $this->branch->id)->update(['quantity' => 100]);
        try {
            $this->submit();
            $this->fail('cash stock shortfall must be refused');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Insufficient cash stock', $e->getMessage());
        }

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Insufficient branch wallet');
        $this->submit(['pay_cash' => 0, 'pay_wallet' => 61058.40]);
    }

    public function test_new_customer_is_kept_on_the_order_and_never_saved_as_a_member(): void
    {
        $r = $this->submit([
            'customer_mode' => 'new',
            'member_id' => null,
            'customer' => ['name' => 'Walk-in Priya', 'phone' => '9876543210', 'city' => 'Madurai', 'pincode' => '625001', 'email' => ''],
        ]);

        $this->assertNull($r->member_id);
        $this->assertSame(['name' => 'Walk-in Priya', 'phone' => '9876543210', 'city' => 'Madurai', 'pincode' => '625001'], $r->customer_details);
        $this->assertStringContainsString('Walk-in Priya', $r->customerName());
        $this->assertSame(1, Member::count());   // only the fixture member — nothing new written
    }

    public function test_new_customer_needs_name_and_phone_and_cannot_apply_coins(): void
    {
        try {
            $this->submit(['customer_mode' => 'new', 'member_id' => null, 'customer' => ['name' => 'X']]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertStringContainsString('name and phone', $e->getMessage());
        }

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('only for an existing customer');
        $this->submit(['customer_mode' => 'new', 'member_id' => null, 'customer' => ['name' => 'X', 'phone' => '9'], 'coin_qty' => 2]);
    }

    public function test_existing_customer_is_required_unless_new(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Select the existing customer');
        $this->submit(['member_id' => null]);
    }

    public function test_coins_raise_a_sales_return_and_credit_the_order(): void
    {
        // 5 coins × 0.1 g × ₹5,000 = ₹2,500 credit → due 58,558.40
        $r = $this->submit([
            'coin_qty' => 5,
            'coin_collect_on' => '2026-08-30 11:00',
            'pay_cash' => 58558.40,
        ]);

        $this->assertEquals(2500.0, (float) $r->coin_credit);
        $this->assertEquals(58558.4, (float) $r->paid_amount);

        $sr = $r->salesReturn;
        $this->assertNotNull($sr);
        $this->assertSame(SalesReturn::STATUS_PENDING, $sr->status);
        $this->assertSame($this->member->id, $sr->member_id);
        $this->assertEquals(5.0, (float) $sr->quantity);
        $this->assertEquals(0.5, (float) $sr->grams);
        $this->assertSame('2026-08-30 11:00', $sr->collect_on->format('Y-m-d H:i'));

        // Collected → coins are counted into the distributor's stock (they travel to HQ
        // later via "Coin captured" — see CustomizeOrderTravelTest).
        app(SalesReturnService::class)->markCollected($sr);
        $this->assertSame(SalesReturn::STATUS_COLLECTED, $sr->fresh()->status);
        $this->assertEquals(5.0, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->coin->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', ['branch_id' => $this->branch->id, 'type' => 'sales_return', 'ref_type' => 'sales_return', 'ref_id' => $sr->id]);

        // A customized order is addressed to the supplier and travels the ladder; the
        // plain stock-order Approve is refused.
        $this->assertSame($this->hq->id, (int) $r->current_branch_id);
        $this->expectException(HttpException::class);
        app(BranchOrderService::class)->approve($r->fresh('lines'));
    }

    public function test_rejecting_a_coin_backed_order_cancels_the_pending_return(): void
    {
        $r = $this->submit(['coin_qty' => 5, 'pay_cash' => 58558.40]);
        app(BranchOrderService::class)->reject($r);
        $this->assertSame(SalesReturn::STATUS_CANCELLED, $r->salesReturn->fresh()->status);
    }

    public function test_coin_value_cannot_exceed_the_order_total(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('exceeds the order total');
        $this->submit([
            'lines' => [['material' => 'gold', 'description' => 'Ring', 'grams' => 1]],
            'coin_qty' => 100,   // 10 g of coins against a 1 g ring
            'pay_cash' => 0,
        ]);
    }

    public function test_standalone_sales_return_can_be_recorded_collected_and_cancelled(): void
    {
        $svc = app(SalesReturnService::class);
        $a = $svc->create(['branch_id' => $this->branch->id, 'member_id' => $this->member->id, 'quantity' => 3, 'collect_on' => '2026-09-01 10:30']);
        $this->assertStringStartsWith('SR-', $a->return_no);
        $this->assertEquals(1500.0, (float) $a->credit_value);   // 0.3 g × ₹5,000
        $this->assertSame($this->coin->id, $a->catalog_product_id);   // default coin from settings

        $svc->markCollected($a);
        $this->assertEquals(3.0, (float) Stock::where('branch_id', $this->branch->id)->where('catalog_product_id', $this->coin->id)->value('quantity'));

        $b = $svc->create(['branch_id' => $this->branch->id, 'quantity' => 1]);
        $svc->cancel($b);
        $this->assertSame(SalesReturn::STATUS_CANCELLED, $b->fresh()->status);

        $this->expectException(HttpException::class);
        $svc->markCollected($b);
    }
}
