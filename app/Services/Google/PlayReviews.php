<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google Play Developer API — reviews of the published app (board 2026-10-31 item 14:
 * "how to integrate the Play review" → testimonials are pulled from the store).
 *
 * How it works (no Google SDK needed):
 *   1. A Google Cloud SERVICE ACCOUNT is created and invited in Play Console →
 *      Users & permissions with "View app information" + "Reply to reviews".
 *   2. Its JSON key file path goes to GOOGLE_PLAY_SERVICE_ACCOUNT_JSON (.env); the
 *      app's package name to GOOGLE_PLAY_PACKAGE (com.app.lordicl).
 *   3. This class signs a JWT (RS256) with the key, exchanges it for an OAuth token
 *      (scope androidpublisher) and calls reviews.list.
 *
 * Limits set by Google: the API returns only reviews written or edited in the LAST
 * SEVEN DAYS (older ones are available as a CSV export in Play Console), so the import
 * is scheduled daily and keeps what it has already fetched.
 */
class PlayReviews
{
    public function configured(): bool
    {
        return filled(config('services.google_play.package'))
            && filled(config('services.google_play.service_account_json'))
            && is_file((string) config('services.google_play.service_account_json'));
    }

    /**
     * @return array<int, array{id:string, author:string, rating:int, text:string, language:?string, device:?string, at:\Illuminate\Support\Carbon}>
     */
    public function fetch(int $max = 200): array
    {
        $package = (string) config('services.google_play.package');
        $out = [];
        $token = null;
        do {
            $res = Http::withToken($this->accessToken())
                ->get("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$package}/reviews", array_filter([
                    'maxResults' => 100,
                    'token' => $token,
                    'translationLanguage' => 'en',
                ]))->throw()->json();
            foreach ($res['reviews'] ?? [] as $r) {
                $c = $r['comments'][0]['userComment'] ?? [];
                $out[] = [
                    'id' => (string) ($r['reviewId'] ?? ''),
                    'author' => (string) ($r['authorName'] ?? 'Play Store user'),
                    'rating' => (int) ($c['starRating'] ?? 0),
                    'text' => trim((string) ($c['text'] ?? '')),
                    'language' => $c['reviewerLanguage'] ?? null,
                    'device' => $c['deviceMetadata']['productName'] ?? null,
                    'at' => \Illuminate\Support\Carbon::createFromTimestamp((int) ($c['lastModified']['seconds'] ?? time())),
                ];
            }
            $token = $res['tokenPagination']['nextPageToken'] ?? null;
        } while ($token && count($out) < $max);

        return $out;
    }

    /** OAuth2 access token for the service account (cached for 50 minutes). */
    protected function accessToken(): string
    {
        return Cache::remember('google-play-token', 3000, function () {
            $sa = json_decode((string) file_get_contents((string) config('services.google_play.service_account_json')), true);
            abort_unless(is_array($sa) && ! empty($sa['client_email']) && ! empty($sa['private_key']), 500, 'Service-account JSON is not valid.');

            $now = time();
            $segments = [
                $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->b64(json_encode([
                    'iss' => $sa['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            $signature = '';
            openssl_sign(implode('.', $segments), $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = implode('.', $segments) . '.' . $this->b64($signature);

            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json();

            return (string) $res['access_token'];
        });
    }

    protected function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
