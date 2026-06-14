<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 0 — mobile phone-OTP login over the /api/v1 surface (Sanctum tokens).
 */
class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Never hit the real WhatsApp gateway from tests.
        Http::fake();
    }

    protected function member(string $phone): Member
    {
        return Member::create([
            'member_code' => 'M' . random_int(1000, 9999),
            'name' => 'Test Member',
            'phone' => $phone,
            'joined_on' => now(),
            'placement' => 'level',
            'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    public function test_request_otp_returns_debug_code_outside_production(): void
    {
        $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000001']);

        $res->assertOk()
            ->assertJsonStructure(['message', 'sent', 'expires_in', 'debug_code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $res->json('debug_code'));
        $this->assertDatabaseHas('phone_otps', ['phone' => '9000000001', 'purpose' => 'login']);
    }

    public function test_verify_with_correct_code_issues_token_and_profile(): void
    {
        $member = $this->member('9000000002');

        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000002'])->json('debug_code');

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '9000000002',
            'code' => $code,
            'device_name' => 'pixel-test',
        ]);

        $res->assertOk()
            ->assertJsonPath('mode', 'distributor')
            ->assertJsonPath('member.id', $member->id)
            ->assertJsonPath('member.member_code', $member->member_code)
            ->assertJsonStructure(['token', 'member' => ['id', 'name', 'phone', 'wallet']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Member::class,
            'tokenable_id' => $member->id,
            'name' => 'pixel-test',
        ]);
    }

    public function test_verify_resolves_member_despite_country_code_difference(): void
    {
        // Stored as a bare 10-digit number; the app sends it with +91.
        $member = $this->member('9000000003');
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '+91 90000 00003'])->json('debug_code');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+91 90000 00003', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('member.id', $member->id);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->member('9000000004');
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000004']);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000004', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_verified_phone_without_member_creates_customer(): void
    {
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000005'])->json('debug_code');

        $res = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000005', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('mode', 'customer')
            ->assertJsonPath('member', null)
            ->assertJsonStructure(['token', 'customer' => ['id', 'phone']]);

        $this->assertDatabaseHas('customers', ['phone' => '9000000005']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => \App\Models\Customer::class]);

        // Signing in again with the same phone reuses the customer (no duplicate).
        $code2 = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000005'])->json('debug_code');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000005', 'code' => $code2])->assertOk();
        $this->assertEquals(1, \App\Models\Customer::where('phone', '9000000005')->count());
    }

    public function test_me_reports_distributor_mode_for_member(): void
    {
        $member = $this->member('9000000008');
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000008'])->json('debug_code');
        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000008', 'code' => $code])->json('token');

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('mode', 'distributor')
            ->assertJsonPath('member.id', $member->id);
    }

    public function test_code_is_single_use(): void
    {
        $this->member('9000000006');
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000006'])->json('debug_code');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000006', 'code' => $code])->assertOk();
        // Re-using the same code fails (consumed).
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000006', 'code' => $code])->assertStatus(422);
    }

    public function test_me_and_logout_require_and_consume_token(): void
    {
        $member = $this->member('9000000007');
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9000000007'])->json('debug_code');
        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000007', 'code' => $code])->json('token');

        // Unauthenticated is rejected.
        $this->getJson('/api/v1/me')->assertStatus(401);

        $auth = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/v1/me', $auth)->assertOk()->assertJsonPath('member.id', $member->id);

        $this->postJson('/api/v1/logout', [], $auth)->assertOk();
        // Token is revoked in storage (a fresh HTTP request would then 401; we assert the
        // row is gone rather than re-request, since the auth guard memoizes the user within
        // a single test run).
        $this->assertSame(0, $member->fresh()->tokens()->count());
    }

    public function test_rates_endpoint_is_public(): void
    {
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 95, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        // Public (no token) and wired to the live rate. Compare to the model's own
        // resolver rather than literals, since LiveRate::latestFor() memoizes per process.
        $rate = \App\Models\LiveRate::latestFor('IN');
        $res = $this->getJson('/api/v1/rates')->assertOk()
            ->assertJsonStructure(['gold', 'silver', 'diamond', 'effective_at']);
        $this->assertEqualsWithDelta((float) $rate->gold, (float) $res->json('gold'), 0.001);
        $this->assertEqualsWithDelta((float) $rate->silver, (float) $res->json('silver'), 0.001);
    }
}
