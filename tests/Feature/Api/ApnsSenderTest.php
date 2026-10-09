<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\PushSetting;
use App\Models\Rank;
use App\Notifications\AppNotification;
use App\Services\Push\ApnsSender;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Board 2026-09-21 — iPhones push over native APNs (not Firebase): the app registers
 * provider=apns, the channel routes those tokens to ApnsSender, which signs an ES256
 * provider token with the .p8 key and posts to Apple over HTTP/2.
 */
class ApnsSenderTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('apns.provider_token');
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'AP1', 'name' => 'Apple', 'phone' => '9000000021',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
    }

    /**
     * A throwaway P-256 key in the same PKCS#8 PEM shape Apple's .p8 download has
     * (generated for this test only — never used anywhere real).
     */
    private const TEST_P8 = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg541rg5RnCp/4v3Uh
    mjhGinW1JVrS31iP6IDsY/WdhAihRANCAARMm8xEu/oMEEvofjkD0/mQFDpUaXeA
    RqP1+YK44/huhQlSOaRuqdWd7plwD2RIRc7YgXebG7HIiI2QZNAlc2op
    -----END PRIVATE KEY-----
    PEM;

    protected function configureApns(bool $sandbox = false): void
    {
        config()->set('services.apns', [
            'enabled' => true,
            'key_id' => 'ABC123DEFG',
            'team_id' => 'TEAM123456',
            'bundle_id' => 'com.app.lordicl',
            'private_key' => self::TEST_P8,
            'sandbox' => $sandbox,
        ]);
    }

    public function test_iphone_registers_an_apns_token(): void
    {
        Sanctum::actingAs($this->member, ['*']);

        $this->postJson('/api/v1/device-tokens', ['token' => str_repeat('ab', 32), 'platform' => 'ios', 'provider' => 'apns'])
            ->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', ['token' => str_repeat('ab', 32), 'platform' => 'ios', 'provider' => 'apns']);
    }

    public function test_sender_is_a_noop_until_the_auth_key_is_configured(): void
    {
        Http::fake();

        $result = app(ApnsSender::class)->sendToTokens(['deadbeef'], 'Hi', 'there');

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_sender_posts_an_es256_signed_alert_to_apple(): void
    {
        $this->configureApns();
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        $result = app(ApnsSender::class)->sendToTokens(['deadbeef'], 'Order ready', 'Collect at HQ', ['route' => '/orders/1']);

        $this->assertSame(['ok' => true, 'sent' => 1, 'failed' => 0, 'stale' => []], $result);

        Http::assertSent(function (Request $req) {
            [$h, $c, $s] = explode('.', substr($req->header('authorization')[0], strlen('bearer ')));
            $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
            $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
            $sig = base64_decode(strtr($s, '-_', '+/'));

            return $req->url() === 'https://api.push.apple.com/3/device/deadbeef'
                && $req->header('apns-topic')[0] === 'com.app.lordicl'
                && $req->header('apns-push-type')[0] === 'alert'
                && $header === ['alg' => 'ES256', 'kid' => 'ABC123DEFG']
                && $claims['iss'] === 'TEAM123456'
                && strlen($sig) === 64                              // raw r||s, not DER
                && $req['aps']['alert'] === ['title' => 'Order ready', 'body' => 'Collect at HQ']
                && $req['route'] === '/orders/1';
        });
    }

    public function test_sandbox_flag_targets_the_development_host(): void
    {
        $this->configureApns(sandbox: true);
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response('', 200)]);

        app(ApnsSender::class)->sendToTokens(['deadbeef'], 'Hi', 'there');

        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://api.sandbox.push.apple.com/'));
    }

    public function test_dead_tokens_are_reported_stale(): void
    {
        $this->configureApns();
        Http::fake([
            'api.push.apple.com/3/device/gone' => Http::response(['reason' => 'Unregistered'], 410),
            'api.push.apple.com/3/device/wrongenv' => Http::response(['reason' => 'BadDeviceToken'], 400),
            'api.push.apple.com/3/device/busy' => Http::response(['reason' => 'TooManyRequests'], 429),
            'api.push.apple.com/*' => Http::response('', 200),
        ]);

        $result = app(ApnsSender::class)->sendToTokens(['ok', 'gone', 'wrongenv', 'busy'], 'Hi', 'there');

        $this->assertSame(1, $result['sent']);
        $this->assertSame(3, $result['failed']);
        $this->assertEqualsCanonicalizing(['gone', 'wrongenv'], $result['stale']);   // 429 is transient, keep it
    }

    public function test_admin_settings_row_wins_over_env(): void
    {
        $this->configureApns();
        PushSetting::current()->update(['apns_enabled' => false]);

        $this->assertFalse(app(ApnsSender::class)->enabled());
    }

    public function test_channel_routes_iphones_to_apns_and_androids_to_fcm(): void
    {
        $this->member->deviceTokens()->create(['token' => 'droid', 'platform' => 'android', 'provider' => 'fcm']);
        $this->member->deviceTokens()->create(['token' => 'iphone', 'platform' => 'ios', 'provider' => 'apns']);
        $this->member->deviceTokens()->create(['token' => 'old-iphone', 'platform' => 'ios', 'provider' => 'apns']);

        $fcm = new class extends PushSender {
            public array $received = [];

            public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
            {
                $this->received = $tokens;

                return ['ok' => true, 'sent' => 1, 'failed' => 0, 'stale' => []];
            }
        };
        $apns = new class extends ApnsSender {
            public array $received = [];

            public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
            {
                $this->received = $tokens;

                return ['ok' => false, 'sent' => 1, 'failed' => 1, 'stale' => ['old-iphone']];
            }
        };
        $this->app->instance(PushSender::class, $fcm);
        $this->app->instance(ApnsSender::class, $apns);

        $this->member->notify(new AppNotification('order', 'Hi', 'body', '/orders/1'));

        $this->assertSame(['droid'], $fcm->received);
        $this->assertEqualsCanonicalizing(['iphone', 'old-iphone'], $apns->received);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'old-iphone']);   // pruned
        $this->assertDatabaseHas('device_tokens', ['token' => 'iphone']);
        $this->assertSame(1, $this->member->notifications()->count());             // inbox still recorded
        $this->assertSame(2, DeviceToken::count());
    }
}
