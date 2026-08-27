<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomizeOrderForm;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Support\CustomizeOrderPricing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board batch 2026-08-26 screens render for both personas, the Distributors table
 * carries the thumb photo + Date-of-Join filter, Purchases view is a modal (no view
 * route), and the Customize Order Form submits end-to-end through Livewire.
 */
class CustomizeOrderScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->get('/admin/login');
    }

    protected function distributor(): User
    {
        return User::where('email', 'distributor@lordicl.com')->firstOrFail();
    }

    public function test_new_and_reworked_screens_render_for_admin(): void
    {
        foreach (['customize-order-form', 'sales-returns', 'sales-returns/create', 'members', 'purchases', 'branch-orders', 'order-form', 'commission-setup'] as $slug) {
            $this->actingAs($this->admin)->get("/admin/{$slug}")->assertSuccessful();
        }
    }

    public function test_distributor_reaches_customize_order_form_and_sales_returns(): void
    {
        $dist = $this->distributor();
        foreach (['customize-order-form', 'sales-returns', 'sales-returns/create', 'branch-orders', 'order-form'] as $slug) {
            $this->actingAs($dist)->get("/admin/{$slug}")->assertSuccessful();
        }
    }

    public function test_purchase_view_is_a_modal_not_a_page(): void
    {
        $this->assertArrayNotHasKey('view', \App\Filament\Resources\PurchaseResource::getPages());
        $this->assertArrayNotHasKey('view', \App\Filament\Resources\BranchOrderResource::getPages());
    }

    public function test_distributors_table_shows_thumb_first_and_filters_by_date_of_join(): void
    {
        $rank = \App\Models\Rank::query()->value('id');
        $mk = fn (string $code, string $joined) => Member::create([
            'member_code' => $code, 'name' => 'M ' . $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => $joined, 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank,
            'photo_path' => 'members/' . $code . '.jpg',
        ]);
        $early = $mk('DOJ01', '2026-01-10');
        $late = $mk('DOJ02', '2026-06-15');

        $table = \App\Filament\Resources\MemberResource::table(\Filament\Tables\Table::make(
            new class extends \Filament\Resources\Pages\ListRecords { protected static string $resource = \App\Filament\Resources\MemberResource::class; }
        ));
        $columns = array_keys($table->getColumns());
        $this->assertSame('photo_path', $columns[0]);
        $this->assertInstanceOf(\Filament\Tables\Columns\ImageColumn::class, $table->getColumns()['photo_path']);
        $this->assertSame(['photo_path', 'name', 'father_name', 'address', 'city', 'pincode', 'phone', 'member_code', 'upline.name', 'referrer.name', 'joined_on', 'kyc_verified'], array_slice($columns, 0, 12));

        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Resources\MemberResource\Pages\ListMembers::class)
            ->assertCanSeeTableRecords([$early, $late])
            ->filterTable('joined_on', ['joined_from' => '2026-03-01', 'joined_until' => '2026-12-31'])
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$early]);
    }

    protected function pricingFixture(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        CustomizeOrderPricing::save(['gold_margin_per_g' => 200, 'silver_margin_per_g' => 5, 'gst_pct' => 3]);
        ChargeBracket::query()->delete();
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 50, 'making_pct' => 10, 'wastage_pct' => 0]);
    }

    public function test_customize_order_form_submits_a_new_customer_order_through_livewire(): void
    {
        $this->pricingFixture();
        $branch = Branch::query()->orderBy('id')->firstOrFail();
        $branch->update(['digi_cash_balance' => 50000]);
        // 2 g × (5000+200) = 10,400 + 10% = 11,440 + 3% GST = 11,783.20

        Livewire::actingAs($this->admin)
            ->test(CustomizeOrderForm::class)
            ->fillForm([
                'branch_id' => $branch->id,
                'customer_mode' => 'new',
                'customer' => ['name' => 'Walk-in Priya', 'phone' => '9876543210', 'city' => 'Madurai'],
                'lines' => [['material' => 'gold', 'description' => 'Gold chain', 'grams' => 2]],
                'pay_cash' => 0,
                'pay_wallet' => 11783.20,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $r = BranchOrderRequest::where('source', BranchOrderService::SOURCE_CUSTOMIZE)->firstOrFail();
        $this->assertEquals(11783.2, (float) $r->grand_total);
        $this->assertSame('Gold chain', $r->lines->first()->description);
        $this->assertSame('Walk-in Priya', $r->customer_details['name']);
        $this->assertNull($r->member_id);
        $this->assertEquals(11783.2, (float) $r->pay_wallet);
        $this->assertEquals(50000 - 11783.2, (float) $branch->fresh()->digi_cash_balance);
    }

    public function test_short_payment_is_refused_on_the_form(): void
    {
        $this->pricingFixture();
        $branch = Branch::query()->orderBy('id')->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(CustomizeOrderForm::class)
            ->fillForm([
                'branch_id' => $branch->id,
                'customer_mode' => 'new',
                'customer' => ['name' => 'Walk-in', 'phone' => '9876543210'],
                'lines' => [['material' => 'gold', 'description' => 'Gold chain', 'grams' => 2]],
                'pay_cash' => 0,
                'pay_wallet' => 100,
            ])
            ->call('save')
            ->assertNotified();

        $this->assertDatabaseCount('branch_order_requests', 0);
    }

    public function test_supplier_dealer_sees_orders_placed_with_it_and_customize_badge_label(): void
    {
        $this->pricingFixture();
        $dist = $this->distributor();
        $supplier = Branch::findOrFail($dist->branch_id);
        $buyer = Branch::create(['name' => 'Downstream', 'country' => 'IN', 'level' => 'reseller', 'source_branch_id' => $supplier->id, 'digi_cash_balance' => 10000, 'is_active' => true]);

        // 1 g × 5,200 + 10% = 5,720 + 3% = 5,891.60
        $r = app(BranchOrderService::class)->submitCustomize([
            'branch_id' => $buyer->id,
            'customer_mode' => 'new', 'customer' => ['name' => 'Walk-in', 'phone' => '9'],
            'lines' => [['material' => 'gold', 'description' => 'Bangle', 'grams' => 1]],
            'pay_cash' => 0, 'pay_wallet' => 5891.60,
        ]);

        $this->assertSame('Customize order', BranchOrderService::sourceLabel($r->source));

        Livewire::actingAs($dist)
            ->test(\App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders::class)
            ->assertCanSeeTableRecords([$r])
            ->assertSee('Customize order')
            // customized orders travel: the supplier dealer forwards / rejects, never approves
            ->assertTableActionVisible('forward', $r)
            ->assertTableActionVisible('reject_custom', $r)
            ->assertTableActionHidden('approve', $r);
    }
}
