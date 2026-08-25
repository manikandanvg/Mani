<?php

namespace App\Console\Commands;

use App\Services\Zoom\ZoomApiService;
use App\Services\Zoom\ZoomSdkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot health check of BOTH Zoom marketplace apps this server depends on
 * (board 2026-08-23 "please check both are working"):
 *   1. Server-to-Server OAuth app — creates meetings from the admin panel and
 *      delivers the attendance webhooks (its Secret Token signs them).
 *   2. Meeting SDK app — signs the in-app join page's SDK JWT.
 * Run on the live server after deploy: php artisan zoom:check
 */
class ZoomCheck extends Command
{
    protected $signature = 'zoom:check
        {--create : also create+delete a throw-away test meeting at Zoom}
        {--join= : print a 3-hour signed WEB join URL for a meeting id (or "latest") — open it in a desktop browser to prove Zoom accepts the SDK key}';

    protected $description = 'Verify the Zoom Server-to-Server app, webhook secret and Meeting SDK credentials';

    public function handle(ZoomApiService $api, ZoomSdkService $sdk): int
    {
        $ok = true;

        // --- 1. Server-to-Server OAuth (meeting register + webhooks) ---------
        $this->components->info('Server-to-Server OAuth app (auto-create meetings + webhooks)');
        if (! $api->configured()) {
            $this->components->error('ZOOM_ACCOUNT_ID / ZOOM_CLIENT_ID / ZOOM_CLIENT_SECRET not all set in .env');
            $ok = false;
        } else {
            try {
                $token = $api->token();
                $this->components->twoColumnDetail('OAuth token', $token ? '<fg=green>OK</>' : '<fg=red>FAILED</>');
                if (! $token) {
                    $ok = false;
                } else {
                    $me = Http::withOptions(['verify' => app_ca()])->withToken($token)->get('https://api.zoom.us/v2/users/' . config('services.zoom.host', 'me'));
                    if ($me->successful()) {
                        $this->components->twoColumnDetail('Host user', $me->json('email') . ' (' . ($me->json('type') === 1 ? 'BASIC — 40-min limit, no cloud recording' : 'Licensed') . ')');
                    } elseif ($me->status() === 400 && str_contains((string) $me->json('message'), 'scopes')) {
                        // Meeting-only scopes are enough for what we do; this probe is informational.
                        $this->components->twoColumnDetail('Host user', '<fg=yellow>skipped</> (app has no user:read scope — fine)');
                    } else {
                        $this->components->twoColumnDetail('Host user', '<fg=red>HTTP ' . $me->status() . '</> ' . $me->json('message'));
                        $this->line('  → ZOOM_HOST_USER must be a user in this Zoom account');
                        $ok = false;
                    }

                    if ($this->option('create')) {
                        $m = $api->createMeeting(new \App\Models\Meeting(['title' => 'LORDICL zoom:check test', 'scheduled_at' => now()->addDay(), 'duration_min' => 15]));
                        if ($m) {
                            $this->components->twoColumnDetail('Create meeting', '<fg=green>OK</> id ' . $m['meeting_id']);
                            $del = Http::withOptions(['verify' => app_ca()])->withToken($token)->delete('https://api.zoom.us/v2/meetings/' . $m['meeting_id']);
                            $this->components->twoColumnDetail('Delete test meeting', $del->successful() ? 'OK' : 'HTTP ' . $del->status() . ' (delete it by hand in Zoom)');
                        } else {
                            $this->components->twoColumnDetail('Create meeting', '<fg=red>FAILED</> — see laravel.log; scope meeting:write:admin needed');
                            $ok = false;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->components->error('Zoom API unreachable: ' . $e->getMessage());
                $ok = false;
            }
        }

        // --- webhook -----------------------------------------------------------
        $this->newLine();
        $this->components->info('Event subscription (attendance webhook)');
        $secret = (string) config('services.zoom.webhook_secret');
        $this->components->twoColumnDetail('ZOOM_WEBHOOK_SECRET', $secret !== '' ? '<fg=green>set</>' : '<fg=red>missing</>');
        $this->components->twoColumnDetail('Endpoint URL to paste in Zoom', route('webhooks.zoom'));
        $this->components->twoColumnDetail('Events to subscribe', 'meeting.participant_joined, meeting.participant_left, meeting.ended');
        if ($secret === '') {
            $ok = false;
        }
        if (! str_starts_with(route('webhooks.zoom'), 'https://')) {
            $this->components->warn('APP_URL is not https — Zoom only validates https endpoints');
        }

        // --- 2. Meeting SDK -----------------------------------------------------
        $this->newLine();
        $this->components->info('Meeting SDK app (in-app join page)');
        if (! $sdk->configured()) {
            $this->components->error('ZOOM_SDK_CLIENT_ID / ZOOM_SDK_CLIENT_SECRET not set — the app falls back to the external Zoom link');
            $ok = false;
        } else {
            // Two marketplace apps, two credential sets — the classic mistake is pasting
            // the Server-to-Server app's Client ID/Secret (or the webhook Secret Token)
            // into the ZOOM_SDK_* slots. The SDK then answers AUTHRET_TOKENWRONG (5/124)
            // on the phone with no hint why, so catch it here (2026-08-25).
            $sdkId = $sdk->clientId();
            $sdkSecret = (string) config('services.zoom.sdk_client_secret');
            $this->components->twoColumnDetail('SDK Client ID', substr($sdkId, 0, 4) . '…' . substr($sdkId, -4) . ' (' . strlen($sdkId) . ' chars)');
            $this->components->twoColumnDetail('SDK Client Secret', substr($sdkSecret, 0, 2) . '…' . substr($sdkSecret, -2) . ' (' . strlen($sdkSecret) . ' chars)');
            $this->line('  → both must be copied from the GENERAL app (Meeting SDK enabled), not the Server-to-Server app');

            if ($sdkId === (string) config('services.zoom.client_id')) {
                $this->components->error('ZOOM_SDK_CLIENT_ID equals ZOOM_CLIENT_ID — that is the Server-to-Server app, it cannot sign SDK tokens');
                $ok = false;
            }
            if ($sdkSecret === (string) config('services.zoom.client_secret')) {
                $this->components->error('ZOOM_SDK_CLIENT_SECRET equals ZOOM_CLIENT_SECRET — Server-to-Server secret pasted into the SDK slot');
                $ok = false;
            }
            if ($sdkSecret === (string) config('services.zoom.webhook_secret')) {
                $this->components->error('ZOOM_SDK_CLIENT_SECRET equals ZOOM_WEBHOOK_SECRET — the webhook Secret Token is not the Client Secret');
                $ok = false;
            }

            $sig = $sdk->signature('1234567890');
            $parts = explode('.', $sig);
            $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
            $required = ['appKey', 'sdkKey', 'mn', 'role', 'iat', 'exp', 'tokenExp'];
            $missing = is_array($claims) ? array_diff($required, array_keys($claims)) : $required;
            $window = is_array($claims) ? (int) $claims['exp'] - (int) $claims['iat'] : 0;
            $signsOk = $missing === [] && ($claims['appKey'] ?? null) === $sdkId && $window >= 1800 && $window <= 172800;
            $this->components->twoColumnDetail('SDK JWT', $signsOk
                ? '<fg=green>signs OK</> (claims ' . implode(',', array_keys($claims)) . '; valid ' . intdiv($window, 60) . ' min)'
                : '<fg=red>bad</> (missing ' . implode(',', $missing) . '; window ' . $window . 's)');
            if (! $signsOk) {
                $ok = false;
            }
            $this->components->twoColumnDetail('Server clock (UTC)', gmdate('Y-m-d H:i:s') . ' — must be within ±1 min of real time or Zoom rejects iat/exp');
            $this->components->twoColumnDetail('Web SDK version', (string) config('services.zoom.web_sdk_version'));
            $this->line('  → in the General app, add this origin to the allow-list: ' . url('/'));
            $this->line('  → Marketplace review is NOT needed: unpublished apps may join meetings hosted by the same Zoom account,');
            $this->line('    and every meeting here is created by our own account via the Server-to-Server app.');

            // Split test for a native 5/124: the Web SDK page uses the SAME key and
            // signer. If it joins from a desktop browser the key is good and the
            // fault is native-only; if it also fails, the General app itself
            // (Embed → Meeting SDK toggle, credential set) is the problem.
            if (($want = $this->option('join')) !== null) {
                $meeting = $want === 'latest'
                    ? \App\Models\Meeting::query()->where('is_published', true)->whereNotNull('meeting_id')->latest('scheduled_at')->first()
                    : \App\Models\Meeting::find((int) $want);
                if (! $meeting || preg_replace('/\D/', '', (string) $meeting->meeting_id) === '') {
                    $this->components->error('No published meeting with a numeric Zoom id' . ($want === 'latest' ? '' : " (id {$want})"));
                    $ok = false;
                } else {
                    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('zoom.join', now()->addHours(3), ['meeting' => $meeting->id, 'name' => 'zoom-check']);
                    $this->components->twoColumnDetail('Web join test', "#{$meeting->id} {$meeting->title} (Zoom {$meeting->meeting_id})");
                    $this->line('  ' . $url);
                    $this->line('  → open in Chrome; "Signature is invalid"/"Invalid signature" there = the key/app config, not the phone');
                }
            }
        }

        $this->newLine();
        $ok ? $this->components->info('Zoom: all checks passed') : $this->components->error('Zoom: one or more checks failed');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
