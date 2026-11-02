<?php

namespace Tests\Feature;

use App\Filament\Resources\LiveRateResource\Pages\EditLiveRate;
use App\Models\ActivityLog;
use App\Models\DeviceToken;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-08-11 / 2026-10-31 (20): HQ publishing or changing the board rate is a
 * "newsletter" push + inbox entry to every app-registered member, worded with the move
 * against the previous rate. A re-save with the same figures sends nothing.
 */
class LiveRateBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_rate_on_the_edit_screen_pushes_todays_price_to_every_registered_member(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;
        Http::fake(['*' => Http::response(['name' => 'projects/x/messages/1'], 200)]);
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());

        $rank = Rank::where('depth', 0)->value('id');
        $withApp = Member::create(['member_code' => 'LJW900', 'name' => 'Has App', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        $noApp = Member::create(['member_code' => 'LJW901', 'name' => 'No App', 'phone' => '9000000002', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        DeviceToken::create(['owner_type' => Member::class, 'owner_id' => $withApp->id, 'token' => 'fcm-token-1', 'platform' => 'android', 'provider' => 'fcm']);

        $rate = LiveRate::create(['country' => 'IN', 'currency_code' => 'INR', 'source' => 'manual', 'gold' => 7120, 'silver' => 94, 'effective_at' => now()]);

        // same figures → nothing goes out
        Livewire::test(EditLiveRate::class, ['record' => $rate->id])
            ->fillForm(['gold' => 7120, 'silver' => 94])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(0, DB::table('notifications')->count(), 'a re-save with unchanged figures sends nothing');

        // changed figures → push + inbox to the member with a device, worded with the move
        Livewire::test(EditLiveRate::class, ['record' => $rate->id])
            ->fillForm(['gold' => 7155, 'silver' => 92.5])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Rate changed — 1 member(s) notified');

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $withApp->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $noApp->id)->count(), 'no device → not in the audience');
        $log = ActivityLog::where('action', 'notified')->latest('id')->firstOrFail();
        $this->assertStringContainsString("Today's gold & silver price", $log->summary);
        $body = json_encode($log->details);
        $this->assertStringContainsString('7,155.00', $body);
        $this->assertStringContainsString('up', $body);
        $this->assertStringContainsString('7,120.00', $body);
        $this->assertStringContainsString('92.50', $body);
        $this->assertStringContainsString('down', $body);
    }
}
