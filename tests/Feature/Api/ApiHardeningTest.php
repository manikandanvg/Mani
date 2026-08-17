<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\Aadhaar\AadhaarVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API abuse guards (loophole review, 2026-08-12): OTP request/verify throttling,
 * the paid Aadhaar-OTP cooldown + daily cap, and earnings-leaderboard privacy.
 */
class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function makeMember(string $code, string $phone): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => "Member $code", 'phone' => $phone,
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => \App\Models\Rank::firstOrCreate(
                ['code' => 'MEMBER'],
                ['name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0],
            )->id,
        ]);
    }

    public function test_otp_request_is_throttled_per_phone(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000701'])->assertOk();
        }

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000701'])->assertStatus(429);

        // A different phone from the same IP still works — the per-IP cap is looser
        // so one shop/office NAT can serve several genuine users.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000702'])->assertOk();
    }

    public function test_otp_verify_is_throttled_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000703', 'code' => '000000'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000703', 'code' => '000000'])
            ->assertStatus(429);
    }

    public function test_aadhaar_otp_send_has_server_cooldown_and_daily_cap(): void
    {
        $member = $this->makeMember('HRD1', '9000000704');
        Sanctum::actingAs($member, ['*']);

        // Fake OTP-capable verifier so no real gateway credit is spent.
        $this->app->instance(AadhaarVerifier::class, new class implements AadhaarVerifier
        {
            public function supportsOtp(): bool
            {
                return true;
            }

            public function verify(string $aadhaar): array
            {
                return ['valid' => true, 'message' => 'ok'];
            }

            public function sendOtp(string $aadhaar): array
            {
                return ['ok' => true, 'ref_id' => 'ref-1', 'message' => 'OTP sent.'];
            }

            public function verifyOtp(string $refId, string $otp): array
            {
                return ['valid' => true, 'name' => null, 'message' => 'ok'];
            }
        });

        $payload = ['aadhaar' => '234123412346'];

        $this->postJson('/api/v1/member/kyc/aadhaar/otp', $payload)->assertOk();

        // Immediate resend → cooldown, even though the app also counts down client-side.
        $this->postJson('/api/v1/member/kyc/aadhaar/otp', $payload)
            ->assertStatus(429)
            ->assertJsonStructure(['retry_in']);

        // Burn through the daily cap (skipping the cooldown as time passing would).
        $coolKey = "kyc:aadhaar-otp:cool:{$member->id}";
        foreach (range(2, \App\Http\Controllers\Api\V1\KycController::OTP_DAILY_CAP) as $i) {
            Cache::forget($coolKey);
            $this->postJson('/api/v1/member/kyc/aadhaar/otp', $payload)->assertOk();
        }

        Cache::forget($coolKey);
        $this->postJson('/api/v1/member/kyc/aadhaar/otp', $payload)->assertStatus(429);
    }

    public function test_earnings_leaderboard_hides_other_members_amounts(): void
    {
        $me = $this->makeMember('HRD2', '9000000705');
        $other = $this->makeMember('HRD3', '9000000706');
        MemberWallet::create(['member_id' => $me->id, 'earning_total' => 500]);
        MemberWallet::create(['member_id' => $other->id, 'earning_total' => 900]);

        Sanctum::actingAs($me, ['*']);

        $res = $this->getJson('/api/v1/community/leaderboard?metric=earnings')->assertOk();

        $rows = collect($res->json('top'));
        $mine = $rows->firstWhere('mine', true);
        $theirs = $rows->firstWhere('member_code', 'HRD3');

        $this->assertEquals(500, $mine['value']);          // my own amount stays visible
        $this->assertNull($theirs['value']);               // others: order only, no amount
        $this->assertSame(1, $theirs['rank']);             // ordering itself is intact
        $this->assertSame('Distributor', $theirs['tier']); // tier fallback never empty
        $this->assertFalse($theirs['titled']);

        // Non-income boards are unchanged — values stay public.
        $bv = $this->getJson('/api/v1/community/leaderboard?metric=bv')->assertOk();
        $this->assertNotNull(collect($bv->json('top'))->firstWhere('member_code', 'HRD3')['value']);
    }
}
