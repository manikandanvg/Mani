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
    /** User 2026-11-01: the migration day stacked up bills — HQ may back-date a bill; dealers cannot. */
    public function test_hq_super_admin_can_back_date_a_bill_and_everything_follows_that_date(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $branch = Branch::create(['name' => 'HQ Bill', 'country' => 'IN', 'is_active' => true]);
        $dealer = User::create(['name' => 'Dealer', 'email' => 'dealer@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $dealer->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // the field exists for HQ only
        $this->actingAs($admin);
        $this->assertTrue(\App\Filament\Pages\Sales::canBackdate());
        config(['services.payout.cutoff_password' => 'cut-off-test']);
        $today = now()->toDateString();
        $yday = now()->subDay()->toDateString();
        \Livewire\Livewire::test(\App\Filament\Pages\Sales::class)
            ->assertFormFieldExists('bill_date')->assertFormFieldIsVisible('bill_date')
            ->assertFormComponentActionVisible('bill_date', 'unlock')
            // picking a date while protected snaps back to today and opens the password popup
            ->fillForm(['bill_date' => $yday])
            ->assertFormSet(['bill_date' => $today])
            // (Filament's assertFormComponentActionMounted helper re-pushes the component path, so assert on the instance)
            ->tap(fn ($t) => $this->assertSame(['unlock'], $t->instance()->mountedFormComponentActions, 'password popup opened'))
            ->setFormComponentActionData(['password' => 'cut-off-test'])->callMountedFormComponentAction()   // answer the popup
            ->assertHasNoFormComponentActionErrors()
            ->assertFormComponentActionVisible('bill_date', 'lock')
            ->fillForm(['bill_date' => $yday])
            ->assertFormSet(['bill_date' => $yday])
            ->callFormComponentAction('bill_date', 'lock')
            ->assertFormSet(['bill_date' => $today])
            ->assertFormComponentActionVisible('bill_date', 'unlock')
            // a wrong password keeps it protected
            ->callFormComponentAction('bill_date', 'unlock', data: ['password' => 'wrong'])
            ->assertHasFormComponentActionErrors(['password'])
            ->tap(fn ($t) => $this->assertFalse($t->instance()->billDateUnlocked));
        $this->actingAs($dealer);
        $this->assertFalse(\App\Filament\Pages\Sales::canBackdate());
        \Livewire\Livewire::test(\App\Filament\Pages\Sales::class)->assertFormFieldIsHidden('bill_date');

        // a back-dated bill: invoice, contract, instalment schedule and commissions carry that date
        $this->actingAs($admin);
        $plan = \App\Models\Plan::create([
            'code' => 'PBD', 'name' => ['en' => 'Backdate plan'], 'plan_type' => 2, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11, 'cbc_value' => 10, 'cbc_count' => 3,
            'ic_schedule' => [5, 3], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 2,
            'is_active' => true, 'available_to' => 'all', 'is_contract' => true, 'is_redeem' => true,
        ]);
        $cp = CatalogProduct::create(['code' => 'G1B', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 50]);
        $upline = \App\Models\Member::firstOrFail();
        // the upline needs bonded value (earning headroom) for an instant-commission line to issue
        \App\Models\Bond::create(['member_id' => $upline->id, 'plan_id' => $plan->id, 'bond_date' => '2026-01-01', 'value' => 500000, 'status' => 'active']);
        $yesterday = now()->subDay()->toDateString();

        $invoice = app(\App\Services\SalesService::class)->generateInvoice([
            'branch_id' => $branch->id, 'plan_id' => $plan->id, 'gold_rate' => 7000, 'bill_date' => $yesterday,
            'upline_code' => $upline->member_code, 'referrer_code' => $upline->member_code,
            'customer' => ['name' => 'Yesterday Cust', 'phone' => '9123456780', 'pan' => 'ABCDE1234F'],
            'cart' => [['catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 2, 'qty' => 2, 'rate' => 7000, 'net_total' => 14000, 'grand_total' => 14420]],
        ]);

        $this->assertSame($yesterday, substr((string) $invoice->date, 0, 10));
        $bond = \App\Models\Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        $this->assertSame($yesterday, substr((string) $bond->bond_date, 0, 10));
        $this->assertSame($yesterday, substr((string) \App\Models\MemberContract::where('bond_id', $bond->id)->value('start_date'), 0, 10));
        $this->assertSame($yesterday, substr((string) \App\Models\Member::where('phone', '9123456780')->value('joined_on'), 0, 10));
        $this->assertSame($yesterday, substr((string) \App\Models\CommissionLedger::where('type', 'IC')->where('bond_id', $bond->id)->value('earned_on'), 0, 10));
    }
}

