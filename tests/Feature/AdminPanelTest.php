<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->adminUser = User::where('email', 'admin@lordicl.com')->firstOrFail();

        // Warm up the Filament panel (first panel request per process otherwise 404s in test harness).
        $this->get('/admin/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')->assertSuccessful();
    }

    public function test_dashboard_loads_for_admin(): void
    {
        $this->actingAs($this->adminUser)->get('/admin')->assertSuccessful();
    }

    /** Index + create pages for every core resource (create pages exercise the form schemas). */
    public function test_resource_pages_render(): void
    {
        $resources = [
            // master + network + catalog
            'branches', 'members', 'ranks', 'plans', 'products',
            'categories', 'currencies', 'languages',
            'mous', 'charge-brackets', 'vendors', 'catalog-products', 'staff',
            // trade
            'purchases',
            // sales + commissions + digi-gold
            'epins', 'sales-invoices',
            'digi-orders', 'digi-queues', 'digi-withdrawals',
            // website / cms
            'cms-pages', 'posts', 'testimonials', 'faqs',
            // system
            'live-rates', 'settings',
            // community modules
            'memos', 'drive-folders', 'drive-files',
            'social-posts', 'social-comments',
        ];

        foreach ($resources as $slug) {
            $this->actingAs($this->adminUser)->get("/admin/{$slug}")
                ->assertSuccessful();
            $this->actingAs($this->adminUser)->get("/admin/{$slug}/create")
                ->assertSuccessful();
        }

        // index-only resources (no create page). commission-ledgers became a read-only
        // VIEW-backed ledger (board 2026-08-10) — rows are written by the engines.
        foreach (['stocks', 'orders', 'rd-entries', 'branch-orders', 'bonds', 'payout-statements', 'commission-ledgers'] as $slug) {
            $this->actingAs($this->adminUser)->get("/admin/{$slug}")->assertSuccessful();
        }
    }

    public function test_sales_billing_page_renders(): void
    {
        // seed a plan + catalog stock so the page's option closures (which read
        // translatable JSON names) actually execute — guards the reset()-on-cast bug.
        $plan = \App\Models\Plan::create([
            'code' => 'PX', 'name' => ['en' => 'Demo Plan'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 100, 'ic_schedule' => ['10'], 'level_schedule' => [],
            'is_active' => true,
        ]);
        $cp = \App\Models\CatalogProduct::create(['code' => 'CP1', 'name' => ['en' => 'Demo Gold'], 'material' => 'gold', 'is_active' => true]);
        \App\Models\Stock::create([
            'branch_id' => \App\Models\Branch::min('id'), 'catalog_product_id' => $cp->id, 'quantity' => 100,
        ]);

        $this->actingAs($this->adminUser)->get('/admin/sales')->assertSuccessful();
    }

    public function test_seed_reference_data_present(): void
    {
        $this->assertDatabaseCount('currencies', 8);
        $this->assertDatabaseCount('languages', 9);
        $this->assertDatabaseCount('ranks', 6);
        $this->assertDatabaseHas('currencies', ['code' => 'INR', 'is_base' => true]);
    }

    protected function distributor(): User
    {
        return User::where('email', 'distributor@lordicl.com')->firstOrFail();
    }

    /** A distributor (semi-admin) must NOT see head-office-only resources or system pages. */
    public function test_distributor_blocked_from_hq_areas(): void
    {
        $dist = $this->distributor();

        foreach (['members', 'plans', 'settings', 'vendors', 'branches', 'payments', 'purchases'] as $slug) {
            $this->actingAs($dist)->get("/admin/{$slug}")->assertForbidden();
        }

        $this->actingAs($dist)->get('/admin/whatsapp-settings')->assertForbidden();
        $this->actingAs($dist)->get('/admin/verification-settings')->assertForbidden();
        $this->actingAs($dist)->get('/admin/genealogy')->assertForbidden();
    }

    public function test_genealogy_page_renders_for_hq(): void
    {
        $this->actingAs($this->adminUser)->get('/admin/genealogy')->assertSuccessful();
    }

    public function test_genealogy_tree_nests_and_counts_descendants(): void
    {
        $rank = \App\Models\Rank::where('depth', 0)->value('id');
        $root = \App\Models\Member::create(['member_code' => 'TR', 'name' => 'Root', 'phone' => '9333333331', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank, 'status' => 'active']);
        $child = \App\Models\Member::create(['member_code' => 'TC', 'name' => 'Child', 'phone' => '9333333332', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank, 'status' => 'active', 'upline_id' => $root->id]);
        \App\Models\Member::create(['member_code' => 'TG', 'name' => 'Grand', 'phone' => '9333333333', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank, 'status' => 'active', 'upline_id' => $child->id]);

        $tree = collect((new \App\Filament\Pages\GenealogyTree)->getTree());
        $rootNode = $tree->firstWhere('id', $root->id);

        $this->assertEquals(2, $rootNode['descendants']);                 // child + grandchild
        $this->assertCount(1, $rootNode['children']);                     // one direct child
        $this->assertEquals($child->id, $rootNode['children'][0]['id']);
        $this->assertEquals(1, $rootNode['children'][0]['descendants']);  // the grandchild
    }

    /** A distributor CAN reach their branch-scoped Trade screens and the Sales billing page. */
    public function test_distributor_can_access_branch_areas(): void
    {
        $dist = $this->distributor();

        foreach (['stocks', 'sales-invoices', 'rd-entries', 'digi-orders', 'digi-queues', 'branch-orders'] as $slug) {
            $this->actingAs($dist)->get("/admin/{$slug}")->assertSuccessful();
        }

        foreach (['sales', 'order-form', 'rd-collection'] as $slug) {
            $this->actingAs($dist)->get("/admin/{$slug}")->assertSuccessful();
        }

        // dashboard renders (retailer widgets execute) for a distributor
        $this->actingAs($dist)->get('/admin')->assertSuccessful();
    }

    /** "View as Dealer": super-admin can step into a distributor's session and back out. */
    public function test_super_admin_can_impersonate_distributor_and_revert(): void
    {
        $dist = $this->distributor();

        // The branch's distributor login is resolvable (drives the eye button).
        $branch = \App\Models\Branch::find($dist->branch_id);
        $this->assertTrue($branch->distributorUser()->exists());
        $this->assertEquals($dist->id, $branch->distributorUser->id);

        // Start impersonation.
        $this->actingAs($this->adminUser)
            ->get("/admin/impersonate/{$dist->id}")
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($dist);
        $this->assertEquals($this->adminUser->id, session('impersonator_id'));

        // Step back out.
        $this->get('/admin/impersonate/leave')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($this->adminUser);
        $this->assertNull(session('impersonator_id'));
    }

    /** A distributor (semi-admin) must not be able to impersonate anyone. */
    public function test_distributor_cannot_impersonate(): void
    {
        $dist = $this->distributor();
        $this->actingAs($dist)->get("/admin/impersonate/{$this->adminUser->id}")->assertForbidden();
    }

    /** Nobody may impersonate the super-admin. */
    public function test_cannot_impersonate_super_admin(): void
    {
        $this->actingAs($this->adminUser)
            ->get("/admin/impersonate/{$this->adminUser->id}")
            ->assertForbidden();
    }

    /** Branch scoping: a distributor's resource query only returns rows for their own branch. */
    public function test_distributor_stock_query_is_branch_scoped(): void
    {
        $dist = $this->distributor();
        $cp = \App\Models\CatalogProduct::create(['code' => 'SCP', 'name' => ['en' => 'Scope Gold'], 'material' => 'gold', 'is_active' => true]);

        $other = \App\Models\Branch::where('id', '!=', $dist->branch_id)->firstOrFail();
        \App\Models\Stock::create(['branch_id' => $dist->branch_id, 'catalog_product_id' => $cp->id, 'quantity' => 10]);
        \App\Models\Stock::create(['branch_id' => $other->id, 'catalog_product_id' => $cp->id, 'quantity' => 99]);

        $this->actingAs($dist);
        $rows = \App\Filament\Resources\StockResource::getEloquentQuery()->get();
        $this->assertCount(1, $rows);
        $this->assertEquals($dist->branch_id, $rows->first()->branch_id);

        // Head office sees both branches (no scope).
        $this->actingAs($this->adminUser);
        $this->assertCount(2, \App\Filament\Resources\StockResource::getEloquentQuery()->get());
    }
}
