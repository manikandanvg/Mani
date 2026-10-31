<?php

namespace Tests\Feature;

use App\Filament\Pages\Sales;
use App\Filament\Resources\BondResource\Pages\ListBonds;
use App\Filament\Resources\MemberContractResource\Pages\ListMemberContracts;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\User;
use App\Support\Mask;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board corrections 2026-10-31 — the small admin rulings: Cloud Data group, read-only
 * distributor list for non-super-admins, folder-only Distributor Place, masked identifiers
 * for dealer logins on the Sales screen, default filters, Gold Saving wording, stream
 * names, rate-change broadcast, idle Monthly Tasks, app price list + store API.
 */
class BoardCorrectionsOct31Test extends TestCase
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

    protected function staff(string $role = 'manager'): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => "{$role}@test.local", 'password' => 'secret123', 'status' => 'active']);
        $u->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    public function test_network_group_is_called_cloud_data_and_chart_view_is_gone(): void
    {
        $this->actingAs($this->admin);
        $html = $this->get('/admin')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Cloud Data', $html);
        $this->assertStringNotContainsString('>Network<', $html);

        $page = $this->get('/admin/genealogy?view=chart')->assertSuccessful()->getContent();
        $this->assertStringNotContainsString('Chart view', $page);
        $this->assertStringContainsString('Each distributor is a folder', $page);
    }

    public function test_only_the_super_admin_may_edit_or_delete_distributors(): void
    {
        $member = Member::firstOrFail();

        $this->actingAs($this->admin);
        $this->assertTrue(MemberResource::canEdit($member));
        $this->assertTrue(MemberResource::canDeleteAny());

        $this->actingAs($this->staff('manager'));
        $this->assertTrue(MemberResource::canViewAny(), 'HQ staff still see the list');
        $this->assertFalse(MemberResource::canEdit($member));
        $this->assertFalse(MemberResource::canDelete($member));
        $this->assertFalse(MemberResource::canDeleteAny());
        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('edit', $member);
        $this->get('/admin/members/' . $member->id . '/edit')->assertForbidden();
    }

    public function test_masks_hide_identifiers_the_way_the_board_asked(): void
    {
        $this->assertSame('98xxxxxx82', Mask::phone('9876543282'));
        $this->assertSame('GQXXXXXXXG', Mask::pan('GQABC1234G'));
        $this->assertSame('XXXX XXXX 1234', Mask::aadhaar('1234 5678 1234'));
        $this->assertSame('ma***@gmail.com', Mask::email('manikandan@gmail.com'));
        $this->assertNull(Mask::phone(null));
    }

    public function test_sales_screen_shows_masked_identifiers_to_a_dealer_but_real_ones_to_hq(): void
    {
        $branch = Branch::create(['name' => 'Dealer M', 'country' => 'IN', 'is_active' => true]);
        $member = Member::firstOrFail();
        $member->update(['phone' => '9876543282', 'pan' => 'GQABC1234G', 'aadhaar' => '123456781234', 'email' => 'cust@example.com']);

        $dealer = User::create(['name' => 'Dealer M', 'email' => 'dealerm@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $dealer->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($dealer);
        $this->assertFalse(Sales::showsSensitive());
        Livewire::test(Sales::class)
            ->fillForm(['mode' => 'existing'])
            ->fillForm(['customer.member_id' => $member->id])
            ->assertFormSet([
                'customer.phone' => '98xxxxxx82',
                'customer.pan' => 'GQXXXXXXXG',
                'customer.aadhaar' => 'XXXX XXXX 1234',
                'customer.name' => $member->name,
            ]);

        $this->actingAs($this->admin);
        $this->assertTrue(Sales::showsSensitive());
        Livewire::test(Sales::class)
            ->fillForm(['mode' => 'existing'])
            ->fillForm(['customer.member_id' => $member->id])
            ->assertFormSet(['customer.phone' => '9876543282', 'customer.pan' => 'GQABC1234G']);
    }

    public function test_contracts_open_on_active_and_settlements_open_on_closed(): void
    {
        $member = Member::firstOrFail();
        $plan = Plan::create([
            'code' => 'PST', 'name' => ['en' => 'Settle test'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 0,
            'billing_margin' => 0, 'is_active' => true, 'available_to' => 'all',
        ]);
        $active = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'bond_date' => '2026-10-01', 'value' => 100, 'status' => 'active']);
        $closed = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'bond_date' => '2025-10-01', 'value' => 100, 'status' => 'closed']);
        $running = MemberContract::create(['contract_no' => 'C-RUN', 'bond_id' => $active->id, 'member_id' => $member->id, 'plan_id' => $plan->id, 'amount' => 100, 'start_date' => '2026-10-01', 'end_date' => '2027-09-01', 'status' => 'active']);
        $expired = MemberContract::create(['contract_no' => 'C-EXP', 'bond_id' => $closed->id, 'member_id' => $member->id, 'plan_id' => $plan->id, 'amount' => 100, 'start_date' => '2025-10-01', 'end_date' => '2026-09-01', 'status' => 'closed']);

        $this->actingAs($this->admin);
        Livewire::test(ListBonds::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$closed]);
        Livewire::test(ListMemberContracts::class)
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$running])
            ->filterTable('stage', 'active')
            ->assertCanSeeTableRecords([$running]);
    }

    public function test_rd_wording_is_gone_from_navigation_and_earning_streams_use_board_names(): void
    {
        $this->actingAs($this->admin);
        $html = $this->get('/admin/rd-collection')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Gold Saving Collection Entry', $html);
        $this->assertStringContainsString('GS Collection (Gold Saving)', $html);
        $this->assertStringNotContainsString('RD Collection', $html);
        $this->assertStringNotContainsString('Monthly Tasks', $html, 'idle module has no nav entry');

        $options = \App\Services\CommissionApprovalService::streamOptions();
        $this->assertArrayNotHasKey('PAIRMATCH', $options);
        $this->assertSame('Sales Incentive (IC)', \App\Services\CommissionApprovalService::label('IC'));
        $this->assertSame('Gold Saving Renewal Margin', \App\Services\CommissionApprovalService::label('RD_RENEWAL_MARGIN'));
    }

    public function test_changing_the_live_rate_broadcasts_the_price_for_today(): void
    {
        $member = Member::firstOrFail();
        $member->deviceTokens()->create(['token' => 'tok-1', 'platform' => 'android']);
        $rate = LiveRate::create(['country' => 'IN', 'currency_code' => 'INR', 'gold' => 7000, 'silver' => 90, 'diamond' => 0, 'source' => 'manual', 'is_override' => true, 'effective_at' => now()]);

        $this->actingAs($this->admin);
        Livewire::test(\App\Filament\Resources\LiveRateResource\Pages\EditLiveRate::class, ['record' => $rate->id])
            ->fillForm(['gold' => 7050, 'silver' => 90])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $member->id)->latest('created_at')->first();
        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString('up ₹50', $data['body'] ?? json_encode($data));
        $this->assertStringContainsString('7,050', $data['body'] ?? '');
    }

    public function test_dealer_static_payment_qr_is_a_virtual_lbox_that_the_wallet_flow_resolves_without_a_box(): void
    {
        $dealer = Branch::create(['name' => 'Dealer SQ', 'country' => 'IN', 'is_active' => true]);
        $lords = Branch::create(['name' => 'Lord SQ', 'country' => 'IN', 'kind' => 'branch', 'is_active' => true]);

        $this->artisan('branches:static-qr')->assertSuccessful();

        $device = $dealer->fresh()->staticQrDevice();
        $this->assertNotNull($device);
        $this->assertSame('qr', $device->board_type);
        $this->assertSame('QR-' . $dealer->id, $device->serial_no);
        $this->assertNull($lords->fresh()->staticQrDevice(), 'dealers only by default');
        $this->assertSame($device->id, $dealer->ensureStaticQr()->id, 'idempotent');

        // the app's existing Wallet → Withdraw at branch scan works without a box check-in
        $resolved = app(\App\Services\Lbox\WalletWithdrawalService::class)->resolveQr($device->uuid);
        $this->assertSame($dealer->id, $resolved->branch_id);

        // store locator carries the same QR; the L-BOX device list does not show the virtual device
        $store = collect($this->getJson('/api/v1/stores')->assertOk()->json('data'))->firstWhere('id', $dealer->id);
        $this->assertStringContainsString('lbox-QR-' . $dealer->id . '.png', $store['payment_qr_url']);
        $this->actingAs($this->admin);
        $this->assertFalse(\App\Filament\Resources\DeviceResource::getEloquentQuery()->whereKey($device->id)->exists());
        Livewire::test(\App\Filament\Resources\BranchResource\Pages\ListBranches::class)
            ->mountTableAction('staticQr', $dealer)
            ->assertSee('lbox-QR-' . $dealer->id . '.png');
    }

    public function test_app_price_list_prices_every_item_from_the_name_weight_and_stores_carry_the_payment_qr(): void
    {
        LiveRate::create(['country' => 'IN', 'currency_code' => 'INR', 'gold' => 7000, 'silver' => 90, 'diamond' => 0, 'source' => 'manual', 'is_override' => true, 'effective_at' => now()]);
        CatalogProduct::create(['code' => 'G500MG', 'name' => ['en' => '500 mg Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        $this->assertSame(0.5, CatalogProduct::weightFromName('500 mg Gold'));
        $this->assertSame(8.0, CatalogProduct::weightFromName('8 g Gold'));
        $this->assertSame(1000.0, CatalogProduct::weightFromName('1 kg Silver'));

        $rows = $this->getJson('/api/v1/price-list')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('name', '500 mg Gold');
        $this->assertNotNull($row);
        $this->assertSame(0.5, $row['weight']);
        $this->assertGreaterThan(3500, $row['price']);
        foreach ($rows as $r) {
            $this->assertGreaterThan(0, $r['price'], 'no ₹0 rows on the phone');
        }

        $branch = Branch::create(['name' => 'Dealer Q', 'country' => 'IN', 'is_active' => true, 'upi_id' => 'dealerq@upi']);
        $store = collect($this->getJson('/api/v1/stores')->assertOk()->json('data'))->firstWhere('id', $branch->id);
        $this->assertSame('dealerq@upi', $store['upi_id']);
        $this->assertStringStartsWith('upi://pay?pa=dealerq%40upi', $store['upi_link']);
        $this->assertStringContainsString('/branches/' . $branch->id . '/payment-qr.png', $store['payment_qr_url']);
        $this->get($store['payment_qr_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
