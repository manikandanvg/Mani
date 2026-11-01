<?php

namespace Tests\Feature;

use App\Filament\Widgets\RetailerStatsOverview;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Stock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Go-live 2026-11-01 wiring: lordicl.com/app/* forwards the OLD Android app to the legacy host;
 * the dealer dashboard shows grams from legacy-imported pieces (no default_weight on the catalog).
 */
class GoLiveWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_app_paths_forward_to_the_old_host_with_method_and_query_kept(): void
    {
        config(['services.legacy_site.url' => 'https://old.lordicl.com']);

        $this->get('/app')->assertStatus(308)->assertRedirect('https://old.lordicl.com/app');
        $this->get('/app/')->assertStatus(308)->assertRedirect('https://old.lordicl.com/app');
        $this->get('/app/home?x=1&y=two')->assertStatus(308)
            ->assertRedirect('https://old.lordicl.com/app/home?x=1&y=two');
        $this->post('/app/login_api/check', ['u' => 'a'])->assertStatus(308)
            ->assertRedirect('https://old.lordicl.com/app/login_api/check');
        $this->get('/app/Member/dashboard/12')->assertStatus(308)
            ->assertRedirect('https://old.lordicl.com/app/Member/dashboard/12');

        // the NEW app's signed genealogy page is not forwarded
        $this->assertNotSame(308, $this->get('/app/genealogy/1')->getStatusCode());
        // the new system's own routes are untouched
        $this->get('/api/v1/ping')->assertStatus(404);
    }

    public function test_dealer_dashboard_grams_come_from_pieces_times_the_name_weight(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $branch = Branch::create(['name' => 'HARSHA JEWELLERS', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $dealer = User::create(['name' => 'Harsha', 'email' => 'harsha@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $dealer->assignRole('distributor');

        // legacy import leaves default_weight empty — the name carries the weight
        $mk = fn (string $name, string $material) => CatalogProduct::create([
            'code' => strtoupper(str_replace(' ', '', $name)), 'name' => ['en' => $name], 'material' => $material,
            'default_weight' => null, 'is_active' => true,
        ]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('1 g Gold', 'gold')->id, 'quantity' => 43]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('500 mg Gold', 'gold')->id, 'quantity' => 15]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('8 g Gold', 'gold')->id, 'quantity' => 2]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('4 g Gold', 'gold')->id, 'quantity' => 2]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('2 g Gold', 'gold')->id, 'quantity' => 1]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('1 g Silver', 'silver')->id, 'quantity' => 10]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('2 g Silver', 'silver')->id, 'quantity' => 10]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('5 g Silver', 'silver')->id, 'quantity' => 3]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $mk('10 g Silver', 'silver')->id, 'quantity' => 3]);

        $this->actingAs($dealer);
        $widget = new RetailerStatsOverview();
        $m = new \ReflectionMethod($widget, 'getStats');
        $m->setAccessible(true);
        $stats = collect($m->invoke($widget))->keyBy(fn ($s) => $s->getLabel());

        $this->assertSame('76.500 g', $stats['Gold stock']->getValue());     // legacy dashboard: 76.50 gm
        $this->assertSame('63 pcs', $stats['Gold stock']->getDescription());
        $this->assertSame('75.000 g', $stats['Silver stock']->getValue());   // legacy dashboard: 75.00 gm
        $this->assertSame('26 pcs', $stats['Silver stock']->getDescription());

        $this->assertEquals(0.5, CatalogProduct::where('code', '500MGGOLD')->first()->unitWeight());
        $this->assertEquals(8.0, CatalogProduct::where('code', '8GGOLD')->first()->gramsFromPieces(1));
    }
}
