<?php

namespace Tests\Feature;

use App\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Filament\Resources\StaffResource\Pages\ListStaff;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** User 2026-11-02 (board: WhatsApp is OTP-only): "Send login details" resets the password and sends it as app push + inbox; the password never reaches the activity log. */
    public function test_send_login_details_resets_the_password_and_sends_it_by_app_push_and_inbox_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $branch = Branch::create(['name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'phone' => '9000000001', 'incharge' => 'Bala', 'country' => 'IN', 'is_active' => true]);
        $member = \App\Models\Member::create(['member_code' => 'LJW24188', 'name' => 'Murugan V', 'phone' => '9876543210', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => \App\Models\Rank::where('depth', 0)->value('id')]);
        $staff = User::create(['name' => 'Murugan', 'email' => 'murugan@test.local', 'phone' => '9876543210', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id, 'member_code' => 'LJW24188']);
        $staff->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        config(['services.whatsapp.test_recipient' => null]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['status' => 'success'], 200)]);

        $this->actingAs($admin);
        Livewire::test(ListStaff::class)
            ->callTableAction('sendDetails', $staff)
            ->assertNotified();

        // no WhatsApp (or any other HTTP) call — the details travel as push + inbox only
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $inbox = DB::table('notifications')->where('notifiable_id', $member->id)->get();
        $this->assertCount(1, $inbox);
        $body = (string) (json_decode($inbox[0]->data, true)['body'] ?? '');
        $this->assertMatchesRegularExpression('/Password: (\S+)/', $body);
        preg_match('/Password: (\S+)/', $body, $m);
        $password = $m[1];
        $this->assertStringContainsString('LORD JEWELLER (SIVAKASI / 9000000001 / Bala)', $body);
        $this->assertStringContainsString('murugan@test.local', $body);
        $this->assertStringContainsString('/admin/login', $body);

        $fresh = $staff->fresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $fresh->password), 'the sent password is the new one');
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('secret123', $fresh->password), 'the old password stops working');

        // the password is in no activity-log row
        $this->assertSame(0, \App\Models\ActivityLog::where('details', 'like', '%' . $password . '%')->count());
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'staff_credentials_sent')->count());

        // a login without a member account cannot be reached: nothing sent, password untouched, button disabled
        $plain = User::create(['name' => 'Accounts', 'email' => 'accounts@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $plain->assignRole('distributor');
        Livewire::test(ListStaff::class)->assertTableActionDisabled('sendDetails', $plain);
        try {
            app(\App\Services\Staff\StaffCredentialsService::class)->send($plain, $admin->id);
            $this->fail('no member account → must refuse');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret123', $plain->fresh()->password));
    }
}
