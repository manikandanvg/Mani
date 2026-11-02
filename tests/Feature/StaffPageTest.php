<?php

namespace Tests\Feature;

use App\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Filament\Resources\StaffResource\Pages\ListStaff;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User 2026-11-02: Staff list shows the phone; the branch dropdown on edit shows
 * "NAME (city / phone / in-charge)" and is searchable on any of those parts, because
 * 1,180 legacy branches share the name "LORD JEWELLER".
 */
class StaffPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_list_shows_phone_and_branch_select_identifies_the_branch(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();

        $a = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'Madurai', 'phone' => '9000000001', 'incharge' => 'Bala', 'country' => 'IN', 'is_active' => true]);
        $b = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'Salem', 'phone' => '9000000002', 'incharge' => 'Ganesan', 'country' => 'IN', 'is_active' => true]);
        $staff = User::create(['name' => 'Online Dealer', 'email' => 'online@test.local', 'phone' => '9876543210', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $b->id]);
        $staff->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame('LORD JEWELLER (Madurai / 9000000001 / Bala)', $a->pickLabel());
        $this->assertSame([$b->id => 'LORD JEWELLER (Salem / 9000000002 / Ganesan)'], Branch::pickOptions('salem'));
        $this->assertSame([$a->id => $a->pickLabel()], Branch::pickOptions('Bala'));
        $this->assertSame([$a->id => $a->pickLabel()], Branch::pickOptions('0000001'));

        $this->actingAs($admin);
        Livewire::test(ListStaff::class)
            ->assertCanRenderTableColumn('phone')
            ->assertTableColumnExists('phone')
            ->assertSee('9876543210')
            ->searchTable('98765')
            ->assertCanSeeTableRecords([$staff]);

        Livewire::test(EditStaff::class, ['record' => $staff->id])
            ->assertFormFieldExists('branch_id', 'form', fn (\Filament\Forms\Components\Select $f) => $f->isSearchable()
                && $f->getOptionLabel() === 'LORD JEWELLER (Salem / 9000000002 / Ganesan)'
                && $f->getSearchResults('madurai') === [$a->id => 'LORD JEWELLER (Madurai / 9000000001 / Bala)'])
            ->fillForm(['branch_id' => $a->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($a->id, $staff->fresh()->branch_id);
    }
}
