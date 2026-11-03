<?php

namespace Tests\Feature;

use App\Filament\Resources\BranchAttendanceResource\Pages\ListBranchAttendances;
use App\Models\Branch;
use App\Models\BranchAttendance;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** User 2026-11-03: every L-BOX table searches, filters by branch / date, and sorts. */
class BranchAttendanceTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_attendance_searches_filters_and_sorts(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        $a = Branch::create(['name' => 'ALPHA JEWELS', 'city' => 'Madurai', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $b = Branch::create(['name' => 'BETA GOLD', 'city' => 'Trichy', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $r1 = BranchAttendance::create(['branch_id' => $a->id, 'date' => '2026-11-01', 'opened_at' => '2026-11-01 09:00:00']);
        $r2 = BranchAttendance::create(['branch_id' => $b->id, 'date' => '2026-11-02', 'opened_at' => '2026-11-02 10:00:00']);
        $r3 = BranchAttendance::create(['branch_id' => $b->id, 'date' => '2026-11-03']);   // never opened = offline

        Livewire::test(ListBranchAttendances::class)
            ->assertCanSeeTableRecords([$r1, $r2, $r3])
            ->searchTable('ALPHA')->assertCanSeeTableRecords([$r1])->assertCanNotSeeTableRecords([$r2, $r3])
            ->searchTable('')
            ->filterTable('branch_id', $b->id)->assertCanSeeTableRecords([$r2, $r3])->assertCanNotSeeTableRecords([$r1])
            ->resetTableFilters()
            ->filterTable('state', 'offline')->assertCanSeeTableRecords([$r3])->assertCanNotSeeTableRecords([$r1, $r2])
            ->resetTableFilters()
            ->filterTable('date', ['from' => '2026-11-02', 'to' => '2026-11-02'])->assertCanSeeTableRecords([$r2])->assertCanNotSeeTableRecords([$r1, $r3])
            ->resetTableFilters()
            ->sortTable('date')->assertCanSeeTableRecords([$r1, $r2, $r3], inOrder: true)
            ->sortTable('date', 'desc')->assertCanSeeTableRecords([$r3, $r2, $r1], inOrder: true);
    }
}
