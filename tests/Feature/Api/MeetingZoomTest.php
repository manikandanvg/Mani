<?php

namespace Tests\Feature\Api;

use App\Models\Meeting;
use App\Models\Member;
use App\Models\Rank;
use App\Services\Zoom\ZoomSdkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * In-app Zoom join (2026-08-12): the meetings API mints a signed Web-SDK page URL
 * per user; the SDK JWT is HS256-signed server-side and scoped to one meeting.
 */
class MeetingZoomTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = Member::create([
            'member_code' => 'ZM1', 'name' => 'Zoom Tester', 'phone' => '9000000700', 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0])->id,
            'status' => 'active',
        ]);
    }

    protected function makeMeeting(): Meeting
    {
        return Meeting::create([
            'title' => 'Monthly Growth Call', 'platform' => 'zoom',
            'join_url' => 'https://zoom.us/j/9876543210', 'meeting_id' => '987 654 3210', 'passcode' => 'gold',
            'scheduled_at' => now()->addHour(), 'duration_min' => 60,
            'visibility' => 'members', 'is_published' => true,
        ]);
    }

    public function test_meetings_carry_signed_app_join_url_when_sdk_configured(): void
    {
        config(['services.zoom.sdk_client_id' => 'zoom_client_x', 'services.zoom.sdk_client_secret' => 'zoom_secret_x']);
        $meeting = $this->makeMeeting();

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/meetings')->assertOk();

        $url = $res->json('upcoming.0.app_join_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/zoom/join/' . $meeting->id, $url);
        $this->assertStringContainsString('name=Zoom%20Tester', $url);
        $this->assertStringContainsString('signature=', $url);

        // The signed page renders with the SDK bootstrap.
        $this->get($url)->assertOk()
            ->assertSee('zoom-meeting-embedded', false)
            ->assertSee('9876543210', false);

        // Tampering with the signature is refused.
        $this->get(str_replace('name=Zoom%20Tester', 'name=Impostor', $url))->assertStatus(403);
    }

    /**
     * The Web SDK decodes video through WASM into a SharedArrayBuffer, which
     * browsers only expose on a cross-origin-isolated page. Without both headers
     * the SDK throws during init and never defines ZoomMtgEmbedded — the app
     * showed "Could not load the meeting player" and no reason why.
     */
    public function test_join_page_is_cross_origin_isolated_for_sharedarraybuffer(): void
    {
        config(['services.zoom.sdk_client_id' => 'zoom_client_x', 'services.zoom.sdk_client_secret' => 'zoom_secret_x']);
        $this->makeMeeting();

        Sanctum::actingAs($this->member, ['*']);
        $url = $this->getJson('/api/v1/meetings')->json('upcoming.0.app_join_url');

        $this->get($url)
            ->assertOk()
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
    }

    public function test_join_page_offers_a_native_zoom_fallback_carrying_the_passcode(): void
    {
        config(['services.zoom.sdk_client_id' => 'zoom_client_x', 'services.zoom.sdk_client_secret' => 'zoom_secret_x']);
        $this->makeMeeting();

        Sanctum::actingAs($this->member, ['*']);
        $url = $this->getJson('/api/v1/meetings')->json('upcoming.0.app_join_url');

        // A member must never be stranded on a dead player: the page itself
        // carries a hand-off to the Zoom app, passcode included so they are not
        // asked for one they were never shown.
        $this->get($url)->assertOk()
            ->assertSee('https://zoom.us/j/9876543210?pwd=gold', false)
            ->assertSee('Open in the Zoom app', false);
    }

    public function test_app_join_url_is_null_when_sdk_not_configured(): void
    {
        config(['services.zoom.sdk_client_id' => null, 'services.zoom.sdk_client_secret' => null]);
        $this->makeMeeting();

        Sanctum::actingAs($this->member, ['*']);
        $this->getJson('/api/v1/meetings')
            ->assertOk()
            ->assertJsonPath('upcoming.0.app_join_url', null);
    }

    public function test_sdk_signature_is_a_valid_hs256_jwt_scoped_to_the_meeting(): void
    {
        config(['services.zoom.sdk_client_id' => 'zoom_client_x', 'services.zoom.sdk_client_secret' => 'zoom_secret_x']);

        $jwt = app(ZoomSdkService::class)->signature('9876543210');
        [$h, $p, $s] = explode('.', $jwt);

        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame('zoom_client_x', $payload['appKey']);
        $this->assertSame('9876543210', $payload['mn']);
        $this->assertSame(0, $payload['role']);
        $this->assertGreaterThanOrEqual(1800, $payload['exp'] - $payload['iat']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", 'zoom_secret_x', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s);
    }
}
