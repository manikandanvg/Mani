<?php

namespace App\Services\Zoom;

use App\Models\Meeting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Zoom REST API via a Server-to-Server OAuth app (board phase-1, 2026-08-21).
 * Saving a Zoom meeting in the admin creates it AT Zoom — id / join link /
 * passcode come back and fill the form fields automatically.
 * Config: services.zoom.{account_id, client_id, client_secret, host}.
 */
class ZoomApiService
{
    public const OAUTH = 'https://zoom.us/oauth/token';

    public const API = 'https://api.zoom.us/v2';

    public function configured(): bool
    {
        return filled(config('services.zoom.account_id'))
            && filled(config('services.zoom.client_id'))
            && filled(config('services.zoom.client_secret'));
    }

    /**
     * Create the meeting at Zoom. Returns ['meeting_id','join_url','passcode']
     * or null on any failure (the admin then fills the fields by hand).
     */
    public function createMeeting(Meeting $m): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        $body = [
            'topic' => mb_substr((string) $m->title, 0, 200),
            'type' => 2,   // scheduled
            'start_time' => $m->scheduled_at->copy()->timezone('Asia/Kolkata')->format('Y-m-d\TH:i:s'),
            'timezone' => 'Asia/Kolkata',
            'duration' => (int) ($m->duration_min ?: 60),
            'agenda' => mb_substr((string) $m->description, 0, 2000),
            'settings' => [
                'join_before_host' => true,
                'waiting_room' => false,
                'approval_type' => 2,   // no registration
                'audio' => 'both',      // computer audio + phone dial-in
                // Indian dial-in numbers instead of the account's US default
                'global_dial_in_countries' => ['IN'],
            ],
        ];

        $res = $this->post($token, $body);

        // Basic (free) Zoom plans have no dial-in at all and reject the country
        // list with code 300 "Country code IN is not available for host" — retry
        // once without it rather than failing the whole create (seen 2026-08-23).
        if ($res && $res->failed() && $res->json('code') === 300
            && str_contains((string) $res->json('message'), 'Country code')) {
            unset($body['settings']['global_dial_in_countries']);
            $res = $this->post($token, $body);
        }

        if (! $res) {
            return null;
        }

        if ($res->failed()) {
            Log::warning('Zoom create-meeting rejected', ['status' => $res->status(), 'body' => $res->json()]);

            return null;
        }

        return [
            'meeting_id' => (string) $res->json('id'),
            'join_url' => (string) $res->json('join_url'),
            'passcode' => (string) $res->json('password'),
        ];
    }

    protected function post(string $token, array $body): ?\Illuminate\Http\Client\Response
    {
        try {
            return Http::withOptions(['verify' => app_ca()])
                ->withToken($token)
                ->timeout(15)
                ->post(self::API . '/users/' . rawurlencode((string) config('services.zoom.host', 'me')) . '/meetings', $body);
        } catch (\Throwable $e) {
            Log::warning('Zoom create-meeting failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Everyone Zoom saw in a finished meeting, all pages merged (2026-08-25).
     * Needs the S2S scope meeting:read:list_past_participants:admin. Null when
     * the call fails (no token, missing scope, meeting never happened).
     * Each entry: id (participant uuid), user_id (in-meeting id — the same id
     * the participant webhooks carry), name, user_email, join_time,
     * leave_time, duration (seconds).
     */
    public function pastParticipants(string $meetingNumber): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        $all = [];
        $next = '';
        do {
            $res = $this->get($token, '/past_meetings/' . rawurlencode($meetingNumber) . '/participants',
                array_filter(['page_size' => 300, 'next_page_token' => $next]));
            if (! $res || $res->failed()) {
                Log::warning('Zoom past-participants rejected', ['meeting' => $meetingNumber, 'status' => $res?->status(), 'body' => $res?->json()]);

                return null;
            }
            $all = array_merge($all, (array) $res->json('participants', []));
            $next = (string) $res->json('next_page_token', '');
        } while ($next !== '');

        return $all;
    }

    protected function get(string $token, string $path, array $query = []): ?\Illuminate\Http\Client\Response
    {
        try {
            return Http::withOptions(['verify' => app_ca()])
                ->withToken($token)
                ->timeout(15)
                ->get(self::API . $path, $query);
        } catch (\Throwable $e) {
            Log::warning('Zoom GET failed: ' . $e->getMessage(), ['path' => $path]);

            return null;
        }
    }

    /** S2S OAuth access token, cached under its ~1h lifetime. */
    public function token(): ?string
    {
        return Cache::remember('zoom.s2s.token', 3000, function () {
            try {
                $res = Http::withOptions(['verify' => app_ca()])
                    ->asForm()
                    ->withBasicAuth((string) config('services.zoom.client_id'), (string) config('services.zoom.client_secret'))
                    ->timeout(15)
                    ->post(self::OAUTH, [
                        'grant_type' => 'account_credentials',
                        'account_id' => (string) config('services.zoom.account_id'),
                    ]);

                return $res->successful() ? (string) $res->json('access_token') : null;
            } catch (\Throwable $e) {
                Log::warning('Zoom OAuth token failed: ' . $e->getMessage());

                return null;
            }
        }) ?: null;
    }
}
