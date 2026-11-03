<?php

namespace Tests\Feature;

use App\Filament\Pages\LoginCodes;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\PhoneOtp;
use App\Models\Rank;
use App\Models\User;
use App\Models\WhatsappSetting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-03: WhatsApp gateway off, no new app build. The app still asks for a login code;
 * the code shows on Login Codes to the distributor's dealer (own branch) and HQ, is pushed to a
 * registered phone, and is never sent on WhatsApp while the gateway is off.
 */
class LoginCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_reaches_the_dealer_page_and_the_registered_phone_but_not_whatsapp(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;
        WhatsappSetting::current()->update(['is_active' => false]);
        config(['services.whatsapp.test_recipient' => null]);
        Http::fake(['*' => Http::response(['name' => 'ok'], 200)]);

        $rank = Rank::where('depth', 0)->value('id');
        $b1 = Branch::create(['name' => 'DEALER ONE', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $b2 = Branch::create(['name' => 'DEALER TWO', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $dealer1 = User::create(['name' => 'D1', 'email' => 'd1@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $b1->id]);
        $dealer1->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $mine = Member::create(['member_code' => 'LJW900', 'name' => 'Murugan', 'phone' => '+91 9876543210', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'branch_id' => $b1->id]);
        $other = Member::create(['member_code' => 'LJW901', 'name' => 'Other', 'phone' => '9000000002', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'branch_id' => $b2->id]);
        DeviceToken::create(['owner_type' => Member::class, 'owner_id' => $mine->id, 'token' => 'fcm-1', 'platform' => 'android', 'provider' => 'fcm']);

        // the app asks for a code: nothing goes to WhatsApp, the registered phone gets a push + inbox copy
        $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])->assertOk()->json();
        $this->assertTrue($res['sent']);
        $this->assertStringContainsString('app notifications', $res['message']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'whatsapp'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $mine->id)->count());
        $code = PhoneOtp::latest('id')->firstOrFail()->code;

        // a distributor without a registered phone: the message points to the dealer
        $res2 = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000002'])->assertOk()->json();
        $this->assertFalse($res2['sent']);
        $this->assertTrue($res2['relay']);
        $this->assertStringContainsString('dealer', $res2['message']);

        // dealer 1 sees only its own distributor's code, masked phone; HQ sees both
        $this->actingAs($dealer1);
        $this->get('/admin/login-codes')->assertOk();
        Livewire::test(LoginCodes::class)
            ->assertSee('Murugan')->assertSee($code)->assertDontSee('Other')
            ->assertDontSee('9876543210');
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        Livewire::test(LoginCodes::class)->assertSee('Murugan')->assertSee('Other')->assertSee('9876543210');

        // the code still logs the person in, once
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code])->assertOk()->assertJsonPath('mode', 'distributor');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876543210', 'code' => $code])->assertStatus(422);

        // a dealer can issue a code for someone at the counter — own branch only
        $this->actingAs($dealer1);
        Livewire::test(LoginCodes::class)->callAction('issue', ['member_id' => $mine->id])->assertNotified();
        $this->assertSame(3, PhoneOtp::count());
        $this->assertTrue(PhoneOtp::latest('id')->first()->expires_at->gt(now()->addMinutes(9)), '10-minute validity');
    }
}
