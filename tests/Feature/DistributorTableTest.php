<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Distributors table, go-live 2026-11-01: one global search over every field (hidden columns
 * included, sub-strings, senior / referrer), cash wallet on the list, every column sortable,
 * the new filters, and no "all" page size (the 500 while paging 17k rows).
 */
class DistributorTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($this->admin);
        $this->get('/admin/login');
    }

    protected function member(string $code, string $name, array $extra = []): Member
    {
        return Member::create(array_merge([
            'member_code' => $code, 'name' => $name, 'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => Rank::where('depth', 0)->value('id'),
        ], $extra));
    }

    public function test_global_search_finds_sub_words_in_hidden_columns_and_the_senior_distributor(): void
    {
        $senior = $this->member('LJW100', 'Senior Person');
        $a = $this->member('LJW101', 'Arul Selvam', ['father_name' => 'Rajakumar Pillai', 'upline_id' => $senior->id, 'city' => 'Sivakasi']);
        $b = $this->member('LJW102', 'Bala Murugan', ['father_name' => 'Ganesan', 'pan' => 'ABCDE1234F', 'city' => 'Madurai']);

        // a sub-word of the father's name — that column is toggled off by default
        Livewire::test(ListMembers::class)->searchTable('kumar')
            ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        // the senior distributor's code and name
        Livewire::test(ListMembers::class)->searchTable('LJW100')
            ->assertCanSeeTableRecords([$senior, $a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListMembers::class)->searchTable('senior')
            ->assertCanSeeTableRecords([$senior, $a])->assertCanNotSeeTableRecords([$b]);
        // PAN, city, two words narrow down
        Livewire::test(ListMembers::class)->searchTable('cde123')
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListMembers::class)->searchTable('madurai bala')
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    public function test_cash_wallet_column_filters_and_sorting(): void
    {
        $rich = $this->member('LJW201', 'Rich One', ['downline_count' => 3, 'pan_verified' => true, 'aadhaar_verified' => true]);
        $poor = $this->member('LJW202', 'Poor One', ['city' => 'Virudhunagar', 'pincode' => '626001']);
        MemberWallet::create(['member_id' => $rich->id, 'cash_balance' => 12500.50]);
        MemberWallet::create(['member_id' => $poor->id, 'cash_balance' => 0]);

        Livewire::test(ListMembers::class)
            ->assertCanRenderTableColumn('wallet.cash_balance')
            ->assertTableColumnFormattedStateSet('wallet.cash_balance', '₹12,500.50', $rich)
            ->sortTable('wallet.cash_balance', 'desc')
            ->assertCanSeeTableRecords([$rich, $poor], inOrder: true)
            ->sortTable('kyc_verified', 'desc')
            ->assertCanSeeTableRecords([$rich, $poor], inOrder: true)
            ->sortTable('upline.name')
            ->assertSuccessful();

        // filters persist in the session, so one instance, reset between filters
        Livewire::test(ListMembers::class)
            ->filterTable('has_cash', true)
            ->assertCanSeeTableRecords([$rich])->assertCanNotSeeTableRecords([$poor])
            ->resetTableFilters()
            ->filterTable('has_team', true)
            ->assertCanSeeTableRecords([$rich])->assertCanNotSeeTableRecords([$poor])
            ->resetTableFilters()
            ->filterTable('kyc', 'verified')
            ->assertCanSeeTableRecords([$rich])->assertCanNotSeeTableRecords([$poor])
            ->resetTableFilters()
            ->filterTable('kyc', 'pending')
            ->assertCanSeeTableRecords([$poor])->assertCanNotSeeTableRecords([$rich])
            ->resetTableFilters()
            ->filterTable('place', ['city' => 'virudhu', 'pincode' => '6260'])
            ->assertCanSeeTableRecords([$poor])->assertCanNotSeeTableRecords([$rich])
            ->resetTableFilters()
            ->filterTable('status', 'inactive')
            ->assertCanNotSeeTableRecords([$rich, $poor])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$rich, $poor]);

        $html = $this->get('/admin/members')->assertSuccessful()->getContent();
        $this->assertStringContainsString('Cash wallet', $html);
    }
}
