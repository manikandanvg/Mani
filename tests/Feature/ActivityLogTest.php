<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Push\Notifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-10-31: every activity is logged — model changes, communications (push /
 * inbox / WhatsApp), OTPs, logins, app requests, console jobs — and HQ reads them on
 * System → Activity Log with filters and the export toolbar.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        ActivityLogger::$enabled = true;   // the seeder switches it off
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->get('/admin/login');
    }

    public function test_model_changes_are_logged_with_their_diff_and_secrets_hidden(): void
    {
        $this->actingAs($this->admin);
        $rate = LiveRate::create(['country' => 'IN', 'currency_code' => 'INR', 'gold' => 7000, 'silver' => 90, 'diamond' => 0, 'source' => 'manual', 'is_override' => true, 'effective_at' => now()]);
        $rate->update(['gold' => 7100]);

        $created = ActivityLog::where('category', 'rates')->where('action', 'created')->where('subject_id', $rate->id)->firstOrFail();
        $this->assertSame('admin', $created->actor_type);
        $this->assertSame($this->admin->id, (int) $created->actor_id);
        $updated = ActivityLog::where('category', 'rates')->where('action', 'updated')->where('subject_id', $rate->id)->firstOrFail();
        $this->assertSame(['from' => 7000, 'to' => 7100], array_map('intval', $updated->details['changes']['gold']));

        // a touch that changes nothing but timestamps is not an activity
        $n = ActivityLog::count();
        $rate->touch();
        $this->assertSame($n, ActivityLog::count());

        // secrets never reach the log
        $setting = \App\Models\PushSetting::current();
        $setting->fill(['fcm_private_key' => '-----BEGIN PRIVATE KEY-----secret'])->save();
        $rows = ActivityLog::where('subject_type', \App\Models\PushSetting::class)->where('subject_id', $setting->id)->get();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('secret', json_encode($row->details));
        }
        $this->assertTrue($rows->contains(fn ($r) => ($r->details['changes']['fcm_private_key']['to'] ?? $r->details['attributes']['fcm_private_key'] ?? null) === '[hidden]'));
    }

    public function test_push_inbox_and_whatsapp_communications_are_logged(): void
    {
        $member = Member::firstOrFail();
        Notifier::to($member, 'rates', 'Rate changed', 'Gold ₹7,100/g', route: '/rates');
        $row = ActivityLog::where('category', 'communication')->where('action', 'notified')->latest('id')->firstOrFail();
        $this->assertSame($member->id, (int) $row->member_id);
        $this->assertSame('inbox', $row->channel, 'no device registered → inbox only');
        $this->assertSame('Rate changed', $row->details['title']);

        $member->deviceTokens()->create(['token' => 'tok-1', 'platform' => 'android']);
        Notifier::to($member, 'rates', 'Rate changed again', 'Gold ₹7,200/g');
        $row = ActivityLog::where('action', 'notified')->latest('id')->firstOrFail();
        $this->assertSame('push', $row->channel);
        $this->assertSame(1, (int) $row->details['delivery']['devices']);

        Http::fake();
        app(\App\Services\Whatsapp\WhatsappSender::class)->sendText('9876543210', 'hello');
        $row = ActivityLog::where('channel', 'whatsapp')->latest('id')->firstOrFail();
        $this->assertSame('98xxxxxx10', $row->details['to']);
        $this->assertStringContainsString('98xxxxxx10', $row->summary);
        $this->assertStringNotContainsString('9876543210', json_encode($row->toArray()));
    }

    public function test_otp_login_and_api_requests_are_logged(): void
    {
        Http::fake();
        $member = Member::firstOrFail();
        $member->update(['phone' => '9000000099']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000099'])->assertOk();
        $sent = ActivityLog::where('category', 'otp')->where('action', 'otp_sent')->latest('id')->firstOrFail();
        $this->assertSame($member->id, (int) $sent->member_id);
        $this->assertStringNotContainsString('9000000099', $sent->summary);
        // the generated code is kept on the row (user 2026-10-31) and shown to the HQ super admin only
        $code = \App\Models\PhoneOtp::latest('id')->value('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertSame($code, $sent->details['otp_code']);
        $this->actingAs($this->admin);
        $this->assertTrue(\App\Filament\Resources\ActivityLogResource::canSeeOtp());
        Livewire::test(ListActivityLogs::class)->filterTable('category', ['otp'])->assertSee($code);
        $this->assertArrayHasKey('otp_code', \App\Filament\Resources\ActivityLogResource::detailsFor($sent));
        auth()->logout();
        $this->assertFalse(\App\Filament\Resources\ActivityLogResource::canSeeOtp());
        $this->assertArrayNotHasKey('otp_code', \App\Filament\Resources\ActivityLogResource::detailsFor($sent));

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000099', 'code' => '000000']);
        $this->assertTrue(ActivityLog::where('action', 'otp_rejected')->exists());

        $api = ActivityLog::where('category', 'app')->where('action', 'like', 'api post%otp/request')->firstOrFail();
        $this->assertSame('api', $api->channel);
        $this->assertSame(200, (int) $api->details['http']);
        $this->assertStringNotContainsString('code', json_encode($api->details['input']));
    }

    public function test_admin_login_is_logged_and_hq_can_filter_and_export_the_log(): void
    {
        // the panel login form ends in Auth::attempt(): a wrong password fires Failed, a right one Login
        $this->assertFalse(auth()->guard('web')->attempt(['email' => 'admin@lordicl.com', 'password' => 'wrong']));
        $this->assertTrue(ActivityLog::where('category', 'auth')->where('action', 'login_failed')->exists());

        $this->actingAs($this->admin);
        auth()->guard('web')->login($this->admin);   // fires the Login event like the panel does
        $this->assertTrue(ActivityLog::where('action', 'login')->where('actor_id', $this->admin->id)->exists());

        Branch::create(['name' => 'Dealer L', 'country' => 'IN', 'is_active' => true]);
        $branchRow = ActivityLog::where('category', 'branch')->where('action', 'created')->latest('id')->firstOrFail();

        $this->get('/admin/activity-logs')->assertSuccessful();
        Livewire::test(ListActivityLogs::class)
            ->filterTable('category', ['branch'])
            ->assertCanSeeTableRecords([$branchRow])
            ->filterTable('category', ['otp'])
            ->assertCanNotSeeTableRecords([$branchRow])
            ->filterTable('category', null)
            ->searchTable('Dealer L')
            ->assertCanSeeTableRecords([$branchRow])
            ->callTableAction('export_csv')
            ->assertFileDownloaded();

        // dealers never see the log
        $dealer = User::create(['name' => 'D', 'email' => 'd@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => 1]);
        $dealer->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->flushSession();
        $this->actingAs($dealer)->get('/admin/activity-logs')->assertForbidden();
    }

    public function test_prune_removes_only_old_rows(): void
    {
        ActivityLogger::log('system', 'test', 'old', ['occurred_at' => now()->subDays(400)]);
        ActivityLogger::log('system', 'test', 'new');
        $this->artisan('activity:prune --days=365')->assertSuccessful();
        $this->assertSame(0, ActivityLog::where('summary', 'old')->count());
        $this->assertSame(1, ActivityLog::where('summary', 'new')->count());
    }
}
