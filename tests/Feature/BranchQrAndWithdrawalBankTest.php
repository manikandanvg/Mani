<?php

namespace Tests\Feature;

use App\Filament\Resources\WalletWithdrawalResource\Pages\ListWalletWithdrawals;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use App\Models\WalletWithdrawal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-02: (1) only dealers and wholesalers show a branch payment QR — area dealers and
 * sub dealers get none, in the panel and in the app's store list; (2) Wallet Withdrawals shows
 * the distributor's bank account for the transfer.
 */
class BranchQrAndWithdrawalBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_area_dealers_and_sub_dealers_have_no_branch_payment_qr_but_wholesalers_do(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $mk = function (string $level) {
            $b = Branch::create(['name' => strtoupper($level) . ' SHOP', 'level' => $level, 'kind' => 'dealer', 'country' => 'IN', 'is_active' => true, 'upi_id' => 'shop@upi']);
            $u = User::create(['name' => $level, 'email' => $level . '@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $b->id]);
            $u->assignRole('distributor');

            return [$b, $u];
        };
        [$area, $areaUser] = $mk('area_dealer');
        [$sub, $subUser] = $mk('sub_dealer');
        [$whole, $wholeUser] = $mk('wholesaler');
        [$taluk, $talukUser] = $mk('taluk');
        [$retail, $retailUser] = $mk('reseller');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // the page's own access rule, checked per login (one full-page request per test keeps Livewire's harness happy)
        foreach ([[$areaUser, false], [$subUser, false], [$retailUser, false], [$wholeUser, true], [$talukUser, true]] as [$u, $allowed]) {
            $this->actingAs($u);
            $this->assertSame($allowed, \App\Filament\Pages\MyBranchQr::canAccess(), $u->name);
        }
        $this->actingAs($areaUser)->get('/admin/my-branch-qr')->assertForbidden();

        // HQ's Branches list offers the Static QR button only where a QR is allowed
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Livewire::test(\App\Filament\Resources\BranchResource\Pages\ListBranches::class)
            ->assertTableActionHidden('staticQr', $area)
            ->assertTableActionHidden('staticQr', $sub)
            ->assertTableActionHidden('staticQr', $retail)
            ->assertTableActionVisible('staticQr', $whole);

        // user 2026-11-03: admin switches the QR on for ONE reseller (and off for a wholesaler)
        $retail->update(['static_qr' => 'on']);
        $whole->update(['static_qr' => 'off']);
        $this->actingAs($retailUser);
        $this->assertTrue(\App\Filament\Pages\MyBranchQr::canAccess(), 'reseller with the override sees My Branch QR');
        $this->actingAs($wholeUser);
        $this->assertFalse(\App\Filament\Pages\MyBranchQr::canAccess(), 'wholesaler switched off loses it');
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Livewire::test(\App\Filament\Resources\BranchResource\Pages\ListBranches::class)
            ->assertTableActionVisible('staticQr', $retail)
            ->assertTableActionHidden('staticQr', $whole)
            ->filterTable('static_qr', 'on')->assertCanSeeTableRecords([$retail])->assertCanNotSeeTableRecords([$whole, $area])
            ->resetTableFilters();
        $overridden = collect($this->getJson('/api/v1/stores')->assertOk()->json('data'));
        $this->assertNotNull($overridden->firstWhere('id', $retail->id)['payment_qr_url']);
        $this->assertNull($overridden->firstWhere('id', $whole->id)['payment_qr_url']);
        $retail->update(['static_qr' => null]);
        $whole->update(['static_qr' => null]);

        $stores = collect($this->getJson('/api/v1/stores')->assertOk()->json('data'));
        $this->assertNull($stores->firstWhere('id', $area->id)['payment_qr_url']);
        $this->assertNull($stores->firstWhere('id', $area->id)['upi_link']);
        $this->assertNull($stores->firstWhere('id', $sub->id)['payment_qr_url']);
        $this->assertNull($stores->firstWhere('id', $retail->id)['payment_qr_url']);
        $this->assertNotNull($stores->firstWhere('id', $whole->id)['payment_qr_url']);
        $this->assertNotNull($stores->firstWhere('id', $whole->id)['upi_link']);
    }

    public function test_wallet_withdrawals_show_the_distributors_bank_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $m = Member::create(['member_code' => 'LJW900', 'name' => 'Murugan', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => Rank::where('depth', 0)->value('id'), 'bank_name' => 'HDFC Bank', 'bank_acno' => '50100123456789', 'ifsc' => 'HDFC0001234']);
        $w = WalletWithdrawal::create(['member_id' => $m->id, 'branch_id' => $branch->id, 'wallet' => 'member_cash', 'amount' => 500, 'status' => 'pending']);

        Livewire::test(ListWalletWithdrawals::class)
            ->assertCanSeeTableRecords([$w])
            ->assertCanRenderTableColumn('member.bank_acno')
            ->assertSee('50100123456789')
            ->assertSee('HDFC Bank')
            ->assertSee('HDFC0001234')
            ->searchTable('50100123456789')
            ->assertCanSeeTableRecords([$w]);
    }
}
