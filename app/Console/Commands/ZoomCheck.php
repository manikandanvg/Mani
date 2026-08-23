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
    protected $signature = 'zoom:check {--create : also create+delete a throw-away test meeting at Zoom}';

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
            $sig = $sdk->signature('1234567890');
            $parts = explode('.', $sig);
            $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
            $this->components->twoColumnDetail('SDK JWT', is_array($claims) && ($claims['sdkKey'] ?? null) === $sdk->clientId()
                ? '<fg=green>signs OK</> (exp ' . date('H:i', $claims['exp']) . ')'
                : '<fg=red>malformed</>');
            $this->components->twoColumnDetail('Web SDK version', (string) config('services.zoom.web_sdk_version'));
            $this->line('  → in the Meeting SDK app, add this origin to the allow-list: ' . url('/'));
        }

        $this->newLine();
        $ok ? $this->components->info('Zoom: all checks passed') : $this->components->error('Zoom: one or more checks failed');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
