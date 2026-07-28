<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\Stock;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\RdCollectionService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RetailerScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function branch(): Branch
    {
        return Branch::create(['name' => 'Retail Shop', 'country' => 'IN', 'bill_margin' => 0, 'is_active' => true]);
    }

    protected function catalog(string $material, array $attrs = []): CatalogProduct
    {
        return CatalogProduct::create(array_merge([
            'code' => strtoupper($material) . random_int(100, 999),
            'name' => ['en' => ucfirst($material) . ' Item'],
            'material' => $material,
            'gst_pct' => 3,
            'is_active' => true,
        ], $attrs));
    }

    // ---- Order Form ----

    public function test_order_submit_is_pending_and_does_not_move_stock(): void
    {
        $branch = $this->branch();
        $gold = $this->catalog('gold', ['making_charge_pct' => 8, 'wastage_charge_pct' => 2, 'hallmark_charge' => 50]);

        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 10]],
        ]);

        $this->assertEquals('pending', $request->status);
        $this->assertEquals(1, $request->no_of_items);
        $this->assertTrue((float) $request->grand_total > 0);
        // nothing in stock until HQ approves
        $this->assertDatabaseMissing('stock', ['branch_id' => $branch->id, 'catalog_product_id' => $gold->id]);
    }

    public function test_hq_approval_moves_ordered_weight_into_branch_stock(): void
    {
        $branch = $this->branch();
        $gold = $this->catalog('gold', ['making_charge_pct' => 8]);

        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 10]],
        ]);

        app(BranchOrderService::class)->approve($request->fresh('lines'));

        $this->assertEquals('approved', $request->fresh()->status);
        $this->assertEquals(10, (float) Stock::where('branch_id', $branch->id)->where('catalog_product_id', $gold->id)->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'type' => 'purchase', 'ref_type' => 'branch_order',
        ]);
    }

    public function test_cash_line_is_priced_as_rupee_value(): void
    {
        // 'cash' material exists only on MySQL (the enum migration is MySQL-only), so unit-test
        // the pricing rule directly with an in-memory product rather than persisting one.
        $cash = new CatalogProduct(['material' => 'cash']);
        $priced = app(BranchOrderService::class)->priceLine($cash, 5000, null);

        $this->assertEquals(1.0, $priced['rate']);
        $this->assertEquals(0.0, $priced['gst']);
        $this->assertEquals(5000.00, $priced['line_total']);
    }

    public function test_empty_order_is_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BranchOrderService::class)->submit(['branch_id' => $this->branch()->id, 'lines' => []]);
    }

    public function test_order_rejects_out_of_range_weight(): void
    {
        $branch = $this->branch();
        $gold = $this->catalog('gold');

        // 1e11 overflows decimal(15,4); the guard rejects it cleanly instead of a DB error.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 100000000000]],
        ]);
    }

    // ---- Order limit = max(member BV, invested); admin exempt ----

    protected int $distSeq = 0;

    protected function distributorWith(float $invested, float $bv): User
    {
        $n = ++$this->distSeq;
        $branch = Branch::create(['name' => "Dist Br {$n}", 'country' => 'IN', 'is_active' => true]);
        $member = Member::create([
            'member_code' => "DST{$n}", 'name' => "Dist {$n}", 'phone' => '900000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active', 'branch_id' => $branch->id, 'bv' => $bv,
        ]);
        $user = User::create([
            'name' => "Dist {$n}", 'email' => "dist{$n}@x.com", 'password' => bcrypt('x'),
            'status' => 'active', 'branch_id' => $branch->id, 'member_code' => $member->member_code, 'invested' => $invested,
        ]);
        $user->assignRole('distributor');

        return $user;
    }

    public function test_order_limit_is_max_of_bv_and_invested(): void
    {
        $this->assertEquals(5000000.0, $this->distributorWith(invested: 2500000, bv: 5000000)->orderLimit());
        $this->assertEquals(9000000.0, $this->distributorWith(invested: 9000000, bv: 1000000)->orderLimit());
    }

    public function test_order_within_limit_passes(): void
    {
        $user = $this->distributorWith(invested: 2500000, bv: 5000000);   // limit ₹50,00,000
        $gold = $this->catalog('gold');                                   // ≈ ₹7,210

        $req = app(BranchOrderService::class)->submit([
            'branch_id' => $user->branch_id, 'requested_by' => $user->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
        ]);

        $this->assertEquals('pending', $req->status);
    }

    public function test_order_exceeding_limit_is_blocked(): void
    {
        $user = $this->distributorWith(invested: 5000, bv: 0);   // limit ₹5,000
        $gold = $this->catalog('gold');                          // ≈ ₹7,210 > limit

        try {
            app(BranchOrderService::class)->submit([
                'branch_id' => $user->branch_id, 'requested_by' => $user->id,
                'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
            ]);
            $this->fail('Expected an over-limit rejection.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
        $this->assertEquals(0, BranchOrderRequest::where('branch_id', $user->branch_id)->count());
    }

    public function test_pending_orders_count_toward_limit(): void
    {
        $user = $this->distributorWith(invested: 10000, bv: 0);   // limit ₹10,000
        BranchOrderRequest::create([
            'request_no' => 'ORD-SEED', 'branch_id' => $user->branch_id, 'no_of_items' => 1,
            'cross_total' => 8000, 'gst_total' => 0, 'grand_total' => 8000, 'payment_type' => 'cash', 'status' => 'pending',
        ]);
        $gold = $this->catalog('gold');   // ≈ ₹7,210; 8000 + 7210 > 10000

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BranchOrderService::class)->submit([
            'branch_id' => $user->branch_id, 'requested_by' => $user->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
        ]);
    }

    public function test_admin_is_exempt_from_order_limit(): void
    {
        // super_admin (not a distributor) — unlimited regardless of any amounts
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $branch = $this->branch();
        $gold = $this->catalog('gold');

        $req = app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id, 'requested_by' => $admin->id,
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
        ]);

        $this->assertEquals('pending', $req->status);
    }

    // ---- RD Collection ----

    protected function rdSetup(): array
    {
        $branch = $this->branch();
        $plan = Plan::create([
            'code' => 'GS', 'name' => ['en' => 'Gold Saving'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 100, 'billing_margin' => 2, 'is_active' => true,
        ]);
        $member = Member::create([
            'member_code' => 'RDM1', 'name' => 'Saver', 'phone' => '9000000010',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active', 'branch_id' => $branch->id,
        ]);
        $bond = Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
            'bond_date' => now(), 'value' => 1000, 'lvlcom_count' => 11, 'status' => 'active',
        ]);
        // cash-stock holding (material 'cash' is MySQL-only; use a sqlite-safe stand-in —
        // the service deducts by stock id, not material)
        $cashCp = $this->catalog('vessel');
        $stock = Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cashCp->id, 'quantity' => 100000]);

        return compact('branch', 'plan', 'member', 'bond', 'stock');
    }

    public function test_rd_collection_records_entry_deducts_cash_and_pays_margin(): void
    {
        ['branch' => $branch, 'bond' => $bond, 'member' => $member, 'stock' => $stock] = $this->rdSetup();

        $entry = app(RdCollectionService::class)->collect([
            'bond_id' => $bond->id, 'branch_id' => $branch->id, 'amount' => 1000, 'cash_stock_id' => $stock->id,
        ]);

        $this->assertEquals(1, $entry->due_count);
        $this->assertEquals(1000.00, (float) $entry->value);
        $this->assertEquals($member->id, $entry->member_id);

        // cash deducted
        $this->assertEquals(99000, (float) $stock->fresh()->quantity);
        // bill margin 2% of 1000 = 20, accrued to the branch
        $this->assertDatabaseHas('reseller_commissions', ['branch_id' => $branch->id, 'com_value' => 20.00, 'com_type_id' => 2]);
        $this->assertEquals(20, (float) $branch->fresh()->bill_margin);
    }

    public function test_rd_collection_increments_due_count(): void
    {
        ['branch' => $branch, 'bond' => $bond, 'stock' => $stock] = $this->rdSetup();
        $svc = app(RdCollectionService::class);

        $first = $svc->collect(['bond_id' => $bond->id, 'branch_id' => $branch->id, 'amount' => 1000, 'cash_stock_id' => $stock->id]);
        $second = $svc->collect(['bond_id' => $bond->id, 'branch_id' => $branch->id, 'amount' => 1000, 'cash_stock_id' => $stock->id]);

        $this->assertEquals(1, $first->due_count);
        $this->assertEquals(2, $second->due_count);
        $this->assertEquals(2, RdEntry::where('bond_id', $bond->id)->count());
    }

    public function test_rd_collection_rejects_non_rd_bond(): void
    {
        $branch = $this->branch();
        $plan = Plan::create(['code' => 'DG', 'name' => ['en' => 'Digital'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);
        $member = Member::create(['member_code' => 'X1', 'name' => 'X', 'phone' => '9000000011', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active']);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 1000, 'status' => 'active']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RdCollectionService::class)->collect(['bond_id' => $bond->id, 'branch_id' => $branch->id, 'amount' => 1000]);
    }

    // ---- Page submit through the form (locks the hidden branch_id state flowing to save) ----

    protected function asDistributor(): User
    {
        $dist = User::where('email', 'distributor@lordicl.com')->firstOrFail();
        $this->actingAs($dist);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $dist;
    }

    public function test_order_form_page_submits_with_session_branch(): void
    {
        $this->travelTo(now()->setTime(10, 0));   // inside the 9am–9pm window
        $dist = $this->asDistributor();
        $gold = $this->catalog('gold', ['making_charge_pct' => 8]);

        Livewire::test(\App\Filament\Pages\OrderForm::class)
            ->set('data.lines', [['catalog_product_id' => $gold->id, 'weight' => 10]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('branch_order_requests', [
            'branch_id' => $dist->branch_id, 'status' => 'pending', 'no_of_items' => 1,
        ]);
    }

    public function test_order_form_blocked_outside_9_to_9_window(): void
    {
        $this->travelTo(now()->setTime(22, 0));   // 10pm — closed
        $dist = $this->asDistributor();
        $gold = $this->catalog('gold', ['making_charge_pct' => 8]);

        Livewire::test(\App\Filament\Pages\OrderForm::class)
            ->set('data.lines', [['catalog_product_id' => $gold->id, 'weight' => 10]])
            ->call('save');

        // nothing submitted while the window is closed
        $this->assertEquals(0, BranchOrderRequest::where('branch_id', $dist->branch_id)->count());
    }

    public function test_ordering_window_hours(): void
    {
        $this->travelTo(now()->setTime(8, 59));
        $this->assertFalse(\App\Filament\Pages\OrderForm::orderingOpen());
        $this->travelTo(now()->setTime(9, 0));
        $this->assertTrue(\App\Filament\Pages\OrderForm::orderingOpen());
        $this->travelTo(now()->setTime(20, 59));
        $this->assertTrue(\App\Filament\Pages\OrderForm::orderingOpen());
        $this->travelTo(now()->setTime(21, 0));
        $this->assertFalse(\App\Filament\Pages\OrderForm::orderingOpen());
    }

    public function test_rd_collection_page_submits_with_session_branch(): void
    {
        $dist = $this->asDistributor();
        $plan = Plan::create(['code' => 'GS2', 'name' => ['en' => 'GS'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 0, 'allocation_bv' => 100, 'billing_margin' => 2, 'is_active' => true]);
        $member = Member::create(['member_code' => 'RDP', 'name' => 'P', 'phone' => '9000000020', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'), 'status' => 'active', 'branch_id' => $dist->branch_id]);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $dist->branch_id, 'bond_date' => now(), 'value' => 1000, 'lvlcom_count' => 11, 'status' => 'active']);
        $cp = $this->catalog('vessel');
        $stock = Stock::create(['branch_id' => $dist->branch_id, 'catalog_product_id' => $cp->id, 'quantity' => 50000]);

        Livewire::test(\App\Filament\Pages\RdCollection::class)
            ->set('data.bond_id', $bond->id)
            ->set('data.amount', 1000)
            ->set('data.cash_stock_id', $stock->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('rd_entries', [
            'bond_id' => $bond->id, 'member_id' => $member->id, 'value' => 1000, 'due_count' => 1, 'branch_id' => $dist->branch_id,
        ]);
    }
}
