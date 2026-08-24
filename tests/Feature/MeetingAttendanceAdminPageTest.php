<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression for the Meeting Participation admin page (2026-08-24): its
 * modifyQueryUsing closure named its parameter `$q`. Filament injects the
 * table query BY NAME (`query`), so an unmatched `Builder $q` fell back to
 * the container, which built a model-less Builder — and the filters form
 * died with "Cannot use ::class on value of type null". The page must simply
 * render.
 */
class MeetingAttendanceAdminPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_participation_page_renders_for_hq_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        $branch = Branch::create(['name' => 'HQ', 'country' => 'IN', 'is_active' => true]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'hq@lordicl', 'password' => bcrypt('x'),
            'status' => 'active', 'branch_id' => $branch->id,
        ]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get('/admin/meeting-attendances')
            ->assertOk()
            ->assertSee('Meeting Participation');
    }
}
