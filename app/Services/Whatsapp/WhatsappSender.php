<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp sender — adopts the legacy tbl_whatsappapi gateway (instance_id +
 * access_token against whatsapp.lordicl.com). Mirrors the proven legacy call:
 *   {url}api/send?number=91XXXXXXXXXX&type=text&message=...&instance_id=..&access_token=..
 * plus a media variant (type=media&media_url=..). Config: services.whatsapp.
 *
 * Never throws into the caller — a messaging failure must not break billing. Returns
 * a result array; callers (queued jobs) decide on retry via the recorded flag.
 */
class WhatsappSender
{
    /** Send a plain text message. */
    public function sendText(string $phone, string $message): array
    {
        return $this->send($phone, ['type' => 'text', 'message' => $message]);
    }

    /** Send a media message (image/pdf/etc.) by public URL, with an optional caption. */
    public function sendMedia(string $phone, string $mediaUrl, string $caption = ''): array
    {
        return $this->send($phone, ['type' => 'media', 'message' => $caption, 'media_url' => $mediaUrl]);
    }

    protected function send(string $phone, array $payload): array
    {
        $cfg = WhatsappSetting::current()->effective();

        // TEST GUARD: redirect every message to the test recipient when configured,
        // so testing never reaches real customers (config/services.whatsapp.test_recipient).
        $testRecipient = config('services.whatsapp.test_recipient');
        if (filled($testRecipient)) {
            $phone = (string) $testRecipient;
        }

        $number = $this->normalize($phone, (string) ($cfg['country_code'] ?? '91'));

        if (! ($cfg['is_active'] ?? true)) {
            return ['ok' => false, 'message' => 'WhatsApp gateway is disabled.'];
        }
        if (empty($cfg['instance_id']) || empty($cfg['access_token']) || empty($cfg['url'])) {
            return ['ok' => false, 'message' => 'WhatsApp gateway not configured.'];
        }
        if ($number === '') {
            return ['ok' => false, 'message' => 'Invalid phone number.'];
        }

        $query = array_merge([
            'number' => $number,
            'instance_id' => $cfg['instance_id'],
            'access_token' => $cfg['access_token'],
        ], $payload);

        try {
            $endpoint = rtrim((string) $cfg['url'], '/') . '/api/send';
            $res = Http::withOptions(['verify' => app_ca()])->timeout(15)->get($endpoint, $query);
            $body = $res->json() ?? ['raw' => $res->body()];
            $ok = $res->successful() && (($body['status'] ?? null) !== 'error');

            if ($ok) {
                Log::info('WhatsApp sent', ['number' => $number, 'type' => $payload['type'] ?? 'text']);
            } else {
                Log::warning('WhatsApp send failed', ['number' => $number, 'body' => $body]);
            }

            // Gateways sometimes return `message` as an array — always hand back a string.
            // On success that array is just the delivery envelope, so show a clean "sent";
            // on failure keep the raw detail for diagnostics.
            $message = $body['message'] ?? ($ok ? 'sent' : 'failed');
            if (! is_string($message)) {
                $message = $ok ? 'sent' : json_encode($message);
            }

            return ['ok' => $ok, 'message' => $message, 'response' => $body];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp send error: ' . $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Normalise to a country-code-prefixed number with digits only (e.g. 919894360870). */
    protected function normalize(string $phone, string $cc): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $digits = ltrim($digits, '0');                 // drop a leading trunk zero
        if (! str_starts_with($digits, $cc) && strlen($digits) <= 10) {
            $digits = $cc . $digits;                     // local 10-digit -> prefix CC
        }

        return $digits;
    }
}
