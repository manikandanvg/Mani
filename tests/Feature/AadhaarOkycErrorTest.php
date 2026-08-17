<?php

namespace Tests\Feature;

use App\Services\Aadhaar\SandboxOtpAadhaarVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UIDAI's OKYC source drops out regularly and Sandbox relays its raw string
 * ("Source Unavailable") straight through. A distributor must never see that —
 * they must be told it is not their OTP and to retry.
 */
class AadhaarOkycErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pan.endpoint', 'https://api.sandbox.co.in');
        Cache::forget(\App\Services\Sandbox\SandboxAuth::CACHE_KEY);
    }

    private function fake(array $verifyBody, int $status = 502): void
    {
        Http::fake([
            '*/authenticate' => Http::response(['access_token' => 'tok'], 200),
            '*/okyc/otp/verify' => Http::response($verifyBody, $status),
            '*/okyc/otp' => Http::response(['data' => ['ref_id' => '123']], 200),
        ]);
    }

    public function test_uidai_source_outage_is_explained_not_echoed(): void
    {
        $this->fake(['code' => 502, 'message' => 'Source Unavailable', 'transaction_id' => 'abc']);

        $res = (new SandboxOtpAadhaarVerifier)->verifyOtp('123', '728954');

        $this->assertFalse($res['valid']);
        $this->assertStringNotContainsString('Source Unavailable', $res['message']);
        $this->assertStringContainsString('not responding', $res['message']);
        $this->assertStringContainsString('Verify OTP again', $res['message']);
    }

    public function test_wrong_otp_still_says_wrong_otp(): void
    {
        $this->fake(['message' => 'Invalid OTP'], 400);

        $res = (new SandboxOtpAadhaarVerifier)->verifyOtp('123', '000000');

        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('not correct', $res['message']);
    }

    public function test_expired_otp_points_at_resend(): void
    {
        $this->fake(['message' => 'OTP has expired'], 400);

        $res = (new SandboxOtpAadhaarVerifier)->verifyOtp('123', '111111');

        $this->assertStringContainsString('Resend OTP', $res['message']);
    }

    public function test_successful_verification_is_unaffected(): void
    {
        $this->fake(['data' => ['status' => 'VALID', 'name' => 'MANIKANDAN V', 'date_of_birth' => '1990-01-01']], 200);

        $res = (new SandboxOtpAadhaarVerifier)->verifyOtp('123', '728954');

        $this->assertTrue($res['valid']);
        $this->assertSame('MANIKANDAN V', $res['name']);
    }
}
