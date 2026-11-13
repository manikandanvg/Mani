<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\Stock;
use App\Models\User;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Board resolution 2026-10-24 (final pre-roll-out corrections): dealer vs Lord's Branch
 * kind, scheme availability per kind, the renamed admin navigation, the Order Requests
 * date filter and the storefront wording.
 */
class BoardRolloutCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function plan(string $code, string $availableTo): Plan
    {
        return Plan::create([
            'code' => $code, 'name' => ['en' => $code], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 0,
            'is_active' => true, 'available_to' => $availableTo,
        ]);
    }

    protected function bill(Branch $branch, Plan $plan)
    {
        $cp = CatalogProduct::firstOrCreate(['code' => 'G1T'], ['name' => ['en' => '1g Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        Stock::updateOrCreate(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id], ['quantity' => 50]);

        return app(SalesService::class)->generateInvoice([
            'branch_id' => $branch->id,
            'plan_id' => $plan->id,
            'gold_rate' => 7000,
            'customer' => ['name' => 'Cust ' . $plan->code . $branch->id, 'phone' => '9' . random_int(100000000, 999999999), 'pan' => 'ABCDE1234F'],
            'cart' => [[
                'catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 1,
                'net_total' => 7000, 'grand_total' => 7210,
            ]],
        ]);
    }

    // ------------------------------------------------------------- kind + availability

    public function test_hq_is_a_lords_branch_and_new_branches_default_to_dealer(): void
    {
        $hq = Branch::where('level', 'hq')->firstOrFail();
        $this->assertSame('branch', $hq->kind);
        $this->assertTrue($hq->isLordBranch());
        $this->assertSame('Sivakasi', $hq->city);

        $dealer = Branch::create(['name' => 'Dealer X', 'country' => 'IN', 'is_active' => true]);
        $this->assertSame('dealer', $dealer->fresh()->kind);
        $this->assertFalse($dealer->isLordBranch());
        $this->assertSame('dealer', $dealer->kindForSchemes());

        $dealer->update(['kind' => Branch::KIND_BRANCH]);
        $this->assertTrue($dealer->fresh()->isLordBranch());
    }

    public function test_scheme_availability_matrix(): void
    {
        $hq = Branch::where('level', 'hq')->firstOrFail();
        $dealer = Branch::create(['name' => 'Dealer X', 'country' => 'IN', 'is_active' => true]);
        $lords = Branch::create(['name' => 'Lord Branch Y', 'country' => 'IN', 'kind' => 'branch', 'is_active' => true]);

        $all = $this->plan('PALL', 'all');
        $branchOnly = $this->plan('PBR', 'branch');
        $dealerOnly = $this->plan('PDL', 'dealer');

        $this->assertTrue($all->availableAt($dealer) && $all->availableAt($lords) && $all->availableAt($hq));
        $this->assertTrue($branchOnly->availableAt($lords) && $branchOnly->availableAt($hq));
        $this->assertFalse($branchOnly->availableAt($dealer));
        $this->assertTrue($dealerOnly->availableAt($dealer));
        $this->assertFalse($dealerOnly->availableAt($lords));
        $this->assertFalse($dealerOnly->availableAt($hq));

        // user 2026-11-13: Head Office (HQ) only — G12 Forward contractors
        $hqOnly = $this->plan('PHQ', 'hq');
        $this->assertTrue($hqOnly->availableAt($hq));
        $this->assertFalse($hqOnly->availableAt($dealer) || $hqOnly->availableAt($lords));
        $this->assertSame([], Plan::availableFor('dealer')->where('code', 'PHQ')->pluck('code')->all());
        $this->assertSame([], Plan::availableFor('branch')->where('code', 'PHQ')->pluck('code')->all());
        $this->assertSame(['PHQ'], Plan::availableFor('branch', true)->where('code', 'PHQ')->pluck('code')->all());

        $this->assertEqualsCanonicalizing(['PALL', 'PDL'], Plan::availableFor('dealer')->whereIn('code', ['PALL', 'PBR', 'PDL'])->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['PALL', 'PBR'], Plan::availableFor('branch')->whereIn('code', ['PALL', 'PBR', 'PDL'])->pluck('code')->all());
    }

    public function test_billing_refuses_a_branch_only_scheme_at_a_dealer(): void
    {
        $dealer = Branch::create(['name' => 'Dealer X', 'country' => 'IN', 'is_active' => true]);
        $branchOnly = $this->plan('PBR', 'branch');

        try {
            $this->bill($dealer, $branchOnly);
            $this->fail('A Lord\'s-Branches-only scheme was billed at a dealer.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString("Lord's Branches only", $e->getMessage());
        }
        $this->assertDatabaseCount('sales_invoices', 0);
    }

    public function test_billing_allows_a_branch_only_scheme_at_a_lords_branch_and_a_dealer_scheme_at_a_dealer(): void
    {
        $dealer = Branch::create(['name' => 'Dealer X', 'country' => 'IN', 'is_active' => true]);
        $lords = Branch::create(['name' => 'Lord Branch Y', 'country' => 'IN', 'kind' => 'branch', 'is_active' => true]);

        $this->bill($lords, $this->plan('PBR', 'branch'));
        $this->bill($dealer, $this->plan('PDL', 'dealer'));
        $this->bill($dealer, $this->plan('PALL', 'all'));

        $this->assertDatabaseCount('sales_invoices', 3);
    }

    public function test_billing_refuses_a_dealer_only_scheme_at_hq(): void
    {
        $hq = Branch::where('level', 'hq')->firstOrFail();
        $this->expectException(HttpException::class);
        $this->bill($hq, $this->plan('PDL', 'dealer'));
    }

    // ------------------------------------------------------------- admin navigation

    public function test_admin_navigation_uses_the_board_labels(): void
    {
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();

        $html = $this->actingAs($admin)->get('/admin/members')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Distributor List', $html);
        $this->assertStringContainsString('Distributor Place', $html);
        $this->assertStringContainsString('Sales &amp; Contracts', $html);
        $this->assertStringContainsString('Settlements', $html);
        $this->assertStringNotContainsString('Sales &amp; Bonds', $html);
        $this->assertStringNotContainsString('>Genealogy<', $html);
    }

    public function test_genealogy_renders_folder_view_by_default_and_chart_on_request(): void
    {
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $rank = Rank::where('depth', 0)->value('id');
        $root = Member::create(['member_code' => 'FR', 'name' => 'Root', 'phone' => '9333333331', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank, 'status' => 'active']);
        Member::create(['member_code' => 'FC', 'name' => 'Child', 'phone' => '9333333332', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank, 'status' => 'active', 'upline_id' => $root->id]);

        $folder = $this->actingAs($admin)->get('/admin/genealogy')->assertSuccessful()->getContent();
        $this->assertStringContainsString('icl-folder', $folder);
        $this->assertStringContainsString('data-code="FC"', $folder);
        $this->assertStringNotContainsString('class="icl-tree"', $folder);

        // board 2026-10-31 item 2: the chart view is retired — ?view=chart still shows the folder view
        $chart = $this->actingAs($admin)->get('/admin/genealogy?view=chart')->assertSuccessful()->getContent();
        $this->assertStringNotContainsString('class="icl-tree"', $chart);
        $this->assertStringContainsString('icl-folder', $chart);
    }

    public function test_order_requests_filter_by_a_date_interval(): void
    {
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $hq = Branch::where('level', 'hq')->firstOrFail();
        $mk = function (string $no, string $day) use ($hq) {
            $r = \App\Models\BranchOrderRequest::create([
                'request_no' => $no, 'branch_id' => $hq->id, 'no_of_items' => 1,
                'cross_total' => 100, 'gst_total' => 0, 'grand_total' => 100, 'payment_type' => 'cash', 'status' => 'pending',
            ]);
            \App\Models\BranchOrderRequest::whereKey($r->id)->update(['created_at' => $day . ' 10:00:00']);

            return $r->fresh();
        };
        $old = $mk('ORD-OLD', '2026-09-05');
        $mid = $mk('ORD-MID', '2026-09-20');
        $new = $mk('ORD-NEW', '2026-10-10');

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders::class)
            ->assertCanSeeTableRecords([$old, $mid, $new])
            ->filterTable('ordered_between', ['from' => '2026-09-10', 'until' => '2026-09-30'])
            ->assertCanSeeTableRecords([$mid])
            ->assertCanNotSeeTableRecords([$old, $new])
            ->filterTable('ordered_between', ['from' => '2026-10-01', 'until' => null])
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old, $mid]);
    }

    // ------------------------------------------------------------- storefront wording

    public function test_storefront_carries_the_board_wording(): void
    {
        $home = $this->get('/')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Global Gold Pvt Ltd', $home);
        $this->assertStringContainsString('Blessed Gold', $home);
        $this->assertStringContainsString('Made with heritage', $home);
        $this->assertStringContainsString('HUID Hallmark Pure 22K, priced to the minute', $home);
        $this->assertStringContainsString('Our New Utility', $home);
        $this->assertStringContainsString('Sivakasi', $home);
        $this->assertStringNotContainsString('Coimbatore', $home);
        $this->assertStringNotContainsString('Fine Jewellery', $home);

        $contact = $this->get('/contact')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Branch / Dealers', $contact);
        $this->assertStringNotContainsString('boutique', $contact);
    }

    public function test_about_page_has_real_content(): void
    {
        $this->seed(\Database\Seeders\StorefrontDemoSeeder::class);
        $about = $this->get('/about')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Global Gold Pvt Ltd', $about);
        $this->assertStringContainsString('Made with heritage', $about);
        $this->assertGreaterThan(1200, mb_strlen(strip_tags($about)));
    }

    public function test_utility_category_replaces_accessories(): void
    {
        $this->assertDatabaseHas('categories', ['slug' => 'ecom-accessories', 'material' => 'utility']);
        $this->assertDatabaseMissing('categories', ['material' => 'accessory']);
        $cat = \App\Models\Category::where('slug', 'ecom-accessories')->first();
        $this->assertSame('Utility', $cat->name['en']);
    }
}
