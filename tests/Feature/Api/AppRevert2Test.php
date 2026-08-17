<?php

namespace Tests\Feature\Api;

use App\Models\KycSetting;
use App\Models\Member;
use App\Models\MobileDevice;
use App\Models\Post;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Services\Aadhaar\ChecksumAadhaarVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile app revert-2 batch (board 2026-08-12): profile + photo upload, device
 * registry, Re-KYC gate (PAN digital / Aadhaar upload→manual), Events & News feed,
 * champions by rank, documents QRs, calculator benefit rows, and request-host
 * image URLs.
 */
class AppRevert2Test extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 10000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->member = Member::create([
            'member_code' => 'RV1', 'name' => 'Revert Tester', 'phone' => '9000000600', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    /** A 12-digit Aadhaar that passes the Verhoeff checksum (found programmatically). */
    protected function validAadhaar(): string
    {
        $verifier = new ChecksumAadhaarVerifier;
        foreach (range(0, 9) as $d) {
            $candidate = '23412341234' . $d;
            if ($verifier->verify($candidate)['valid']) {
                return $candidate;
            }
        }
        $this->fail('No valid Aadhaar checksum digit found.');
    }

    public function test_profile_show_update_and_photo_upload(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->member, ['*']);

        $this->getJson('/api/v1/member/profile')
            ->assertOk()
            ->assertJsonPath('data.member_code', 'RV1')
            ->assertJsonPath('data.kyc.rekyc_required', false);

        $this->postJson('/api/v1/member/profile', [
            'email' => 'rv1@example.com', 'dob' => '1990-05-15', 'address' => '12 Bazaar St',
            'city' => 'Rajapalayam', 'pincode' => '626117', 'upi' => 'rv1@upi',
        ])->assertOk()
            ->assertJsonPath('data.email', 'rv1@example.com')
            ->assertJsonPath('data.upi', 'rv1@upi');

        $res = $this->post('/api/v1/member/profile/photo', [
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($this->member->fresh()->photo_path);
        Storage::disk('public')->assertExists($this->member->fresh()->photo_path);
        $this->assertStringContainsString('/storage/members/', $res->json('photo_url'));

        // The URL follows the REQUEST host (LAN phone / live domain), not APP_URL.
        $lan = $this->getJson('http://192.168.9.9/api/v1/member/profile');
        $this->assertStringContainsString('http://192.168.9.9/storage/members/', $lan->json('data.photo_url'));
    }

    public function test_device_registry_upserts_by_uid(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/device-registry', [
            'device_uid' => 'uid-abc-123', 'device_name' => 'Redmi Note 12',
            'platform' => 'android', 'biometric_enabled' => false, 'app_version' => '1.1.0',
        ])->assertOk();

        $this->postJson('/api/v1/device-registry', [
            'device_uid' => 'uid-abc-123', 'biometric_enabled' => true,
        ])->assertOk();

        $this->assertSame(1, MobileDevice::count());
        $device = MobileDevice::firstOrFail();
        $this->assertTrue($device->biometric_enabled);
        $this->assertSame('Redmi Note 12', $device->device_name);   // earlier fields kept
        $this->assertSame($this->member->id, (int) $device->member_id);
        $this->assertSame('9000000600', $device->phone);
    }

    public function test_rekyc_gate_pan_digital_and_aadhaar_manual_approval(): void
    {
        Storage::fake('public');
        config(['services.pan.driver' => 'fake']);
        KycSetting::current()->update(['rekyc_enabled' => true, 'rekyc_from' => now()->toDateString()]);

        Sanctum::actingAs($this->member, ['*']);

        // Gate is up.
        $this->getJson('/api/v1/member/kyc')
            ->assertOk()
            ->assertJsonPath('rekyc_active', true)
            ->assertJsonPath('rekyc_required', true);

        // PAN — digital, instant.
        $this->postJson('/api/v1/member/kyc/pan', ['pan' => 'ABCDE1234F'])
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertTrue((bool) $this->member->fresh()->pan_verified);

        // Bad PAN format refused.
        $this->postJson('/api/v1/member/kyc/pan', ['pan' => 'NOT-A-PAN'])->assertStatus(422);

        // Aadhaar — checksum + document upload; verified stays FALSE until admin approves.
        $this->post('/api/v1/member/kyc/aadhaar', [
            'aadhaar' => $this->validAadhaar(),
            'doc' => UploadedFile::fake()->image('aadhaar.jpg', 800, 500),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $this->member->fresh();
        $this->assertFalse((bool) $fresh->aadhaar_verified);
        $this->assertNotNull($fresh->aadhaar_doc_path);
        Storage::disk('public')->assertExists($fresh->aadhaar_doc_path);

        // Wrong checksum refused.
        $this->post('/api/v1/member/kyc/aadhaar', [
            'aadhaar' => '234123412340' === $this->validAadhaar() ? '234123412341' : '234123412340',
            'doc' => UploadedFile::fake()->image('a2.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Still required until the admin flips aadhaar_verified…
        $this->getJson('/api/v1/member/kyc')->assertJsonPath('rekyc_required', true);

        // …admin approves (verified_at auto-stamped by the model hook). Update the
        // ACTING instance — Sanctum::actingAs keeps it in memory; a fresh() copy
        // would leave the request user stale.
        $this->member->refresh();
        $this->member->update(['aadhaar_verified' => true]);
        $this->assertNotNull($this->member->fresh()->aadhaar_verified_at);
        $this->getJson('/api/v1/member/kyc')->assertJsonPath('rekyc_required', false);
    }

    public function test_admin_entered_sandbox_creds_override_env(): void
    {
        // .env says fake/no creds; the admin enters live values in Verification Settings.
        config(['services.pan.driver' => 'fake', 'services.pan.key' => null, 'services.pan.secret' => null]);
        KycSetting::current()->update([
            'pan_driver' => 'sandbox',
            'sandbox_key' => 'key_live_admin',
            'sandbox_secret' => 'secret_live_admin',
        ]);

        $verifier = app(\App\Services\Pan\PanVerifier::class);

        $this->assertInstanceOf(\App\Services\Pan\SandboxPanVerifier::class, $verifier);
        $this->assertSame('key_live_admin', config('services.pan.key'));
        $this->assertSame('secret_live_admin', config('services.pan.secret'));

        // Cleared DB values fall back to .env.
        KycSetting::current()->update(['pan_driver' => null, 'sandbox_key' => null, 'sandbox_secret' => null]);
        config(['services.pan.key' => 'env_key']);
        app(\App\Services\Pan\PanVerifier::class);
        $this->assertSame('env_key', config('services.pan.key'));
    }

    public function test_aadhaar_otp_flow_verifies_instantly_when_enabled(): void
    {
        KycSetting::current()->update(['aadhaar_otp_enabled' => true]);

        // Fake the UIDAI leg — the container binding is what the controller resolves.
        $fake = new class implements \App\Services\Aadhaar\AadhaarVerifier
        {
            public function supportsOtp(): bool { return true; }
            public function verify(string $aadhaar): array { return ['valid' => true, 'message' => 'ok']; }
            public function sendOtp(string $aadhaar): array { return ['ok' => true, 'ref_id' => 'REF123', 'message' => 'OTP sent']; }
            public function verifyOtp(string $refId, string $otp): array
            {
                return $refId === 'REF123' && $otp === '123456'
                    ? ['valid' => true, 'name' => 'REVERT TESTER', 'message' => 'ok']
                    : ['valid' => false, 'name' => null, 'message' => 'Wrong OTP'];
            }
        };
        $this->app->instance(\App\Services\Aadhaar\AadhaarVerifier::class, $fake);

        Sanctum::actingAs($this->member, ['*']);

        // Status advertises OTP mode so the app renders the OTP flow.
        $this->getJson('/api/v1/member/kyc')->assertJsonPath('aadhaar_otp_mode', true);

        $aadhaar = $this->validAadhaar();
        $send = $this->postJson('/api/v1/member/kyc/aadhaar/otp', ['aadhaar' => $aadhaar])->assertOk();
        $this->assertSame('REF123', $send->json('ref_id'));

        // Wrong OTP refused, nothing changes.
        $this->postJson('/api/v1/member/kyc/aadhaar/otp/verify', [
            'aadhaar' => $aadhaar, 'ref_id' => 'REF123', 'otp' => '999999',
        ])->assertStatus(422);
        $this->assertFalse((bool) $this->member->fresh()->aadhaar_verified);

        // Correct OTP verifies INSTANTLY — no photo, no manual approval.
        $this->postJson('/api/v1/member/kyc/aadhaar/otp/verify', [
            'aadhaar' => $aadhaar, 'ref_id' => 'REF123', 'otp' => '123456',
        ])->assertOk()->assertJsonPath('ok', true);

        $fresh = $this->member->fresh();
        $this->assertTrue((bool) $fresh->aadhaar_verified);
        $this->assertNotNull($fresh->aadhaar_verified_at);
    }

    public function test_aadhaar_otp_endpoints_refused_when_toggle_off(): void
    {
        KycSetting::current()->update(['aadhaar_otp_enabled' => false]);
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/member/kyc/aadhaar/otp', ['aadhaar' => $this->validAadhaar()])
            ->assertStatus(422);
    }

    public function test_rekyc_window_bounds(): void
    {
        KycSetting::current()->update([
            'rekyc_enabled' => true,
            'rekyc_from' => now()->addDays(5)->toDateString(),   // starts in the future
        ]);
        $this->assertFalse(KycSetting::rekycActive());

        KycSetting::current()->update([
            'rekyc_from' => now()->subDays(10)->toDateString(),
            'rekyc_until' => now()->subDay()->toDateString(),    // already over
        ]);
        $this->assertFalse(KycSetting::rekycActive());

        KycSetting::current()->update(['rekyc_until' => now()->addDays(10)->toDateString()]);
        $this->assertTrue(KycSetting::rekycActive());
    }

    public function test_posts_feed_and_champions(): void
    {
        Post::create([
            'type' => 'news', 'slug' => 'diwali-mela', 'title' => ['en' => 'Diwali Mela'],
            'excerpt' => ['en' => 'Festive offers'], 'body' => ['en' => '<p>Come join!</p>'],
            'image_path' => 'posts/mela.jpg', 'is_published' => true, 'published_at' => now()->subDay(),
        ]);
        Post::create([
            'type' => 'news', 'slug' => 'unpublished', 'title' => ['en' => 'Hidden'],
            'is_published' => false, 'published_at' => now(),
        ]);

        $this->getJson('/api/v1/posts?type=news')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Diwali Mela');
        // image URL follows the request host
        $this->assertStringContainsString('http://192.168.9.9/storage/posts/mela.jpg',
            $this->getJson('http://192.168.9.9/api/v1/posts?type=news')->json('data.0.image'));

        // Champions grouped by rank, highest tier first.
        $director = Rank::create(['code' => 'TALUK_DIRECTOR', 'name' => ['en' => 'Taluk Director'], 'depth' => 1, 'target_bv' => 0, 'is_active' => true]);
        Member::create([
            'member_code' => 'CH1', 'name' => 'Champ One', 'phone' => '9000000601', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => $director->id, 'status' => 'active', 'bv' => 9000, 'city' => 'Madurai',
        ]);

        Sanctum::actingAs($this->member, ['*']);
        $this->getJson('/api/v1/community/champions')
            ->assertOk()
            ->assertJsonPath('data.0.rank', 'Taluk Director')
            ->assertJsonPath('data.0.members.0.member_code', 'CH1');
    }

    public function test_documents_include_redeemable_qrs(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->member, ['*']);

        $plan = \App\Models\Plan::create([
            'code' => 'PQ1', 'name' => ['en' => 'QR Plan'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 100, 'is_redeem' => true, 'is_active' => true,
        ]);
        $bond = \App\Models\Bond::create([
            'member_id' => $this->member->id, 'plan_id' => $plan->id, 'bond_date' => now()->toDateString(),
            'value' => 100000, 'invoice_no' => 'INV-77', 'status' => 'active',
        ]);
        RedeemableQr::create([
            'bond_id' => $bond->id, 'member_id' => $this->member->id,
            'qr_code' => 'STOCKQR-TEST01', 'qr_mode' => 'gold',
            'gram_worth' => 10.5, 'cash_worth' => 105000, 'invoice_no' => 'INV-77',
        ]);

        $res = $this->getJson('/api/v1/member/documents')->assertOk();
        $this->assertCount(1, $res->json('qrs'));
        $this->assertSame('STOCKQR-TEST01', $res->json('qrs.0.qr_code'));
        $this->assertSame(10.5, (float) $res->json('qrs.0.gram_worth'));
        $this->assertStringContainsString('/storage/qr/', $res->json('qrs.0.image_url'));
    }

    public function test_calculator_returns_contract_benefit_rows(): void
    {
        $plan = \App\Models\Plan::create([
            'code' => 'P209', 'name' => ['en' => 'Gold Savings'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 100, 'allocation_bv' => 100, 'validity_months' => 11, 'is_active' => true,
        ]);

        $res = $this->postJson('/api/v1/calculator', ['plan_id' => $plan->id, 'amount' => 1000])
            ->assertOk();

        $benefits = $res->json('benefits');
        $this->assertNotEmpty($benefits);
        $this->assertStringContainsString('11 months', $benefits[0]['label']);
        $this->assertStringContainsString('11,000', $benefits[0]['value']);       // 1000 × 11
        $this->assertStringContainsString('12,000', end($benefits)['value']);     // maturity 11 + 1 bonus
    }
}
