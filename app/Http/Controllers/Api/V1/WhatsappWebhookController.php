<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Inbound WhatsApp (Phase 4c-2). The gateway POSTs incoming customer messages here;
 * we match the sender to a Member/Customer by phone and append the text to their
 * support thread (source = 'whatsapp'), so HQ answers everything from one place.
 *
 * The gateway's exact payload shape isn't pinned down, so parsing is tolerant of the
 * common ones (flat {from,message}, nested {data:{...}}, and Meta Cloud API
 * entry[].changes[].value.messages[]). Always returns 200 so the gateway doesn't retry.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(protected SupportService $support) {}

    /** GET — Meta-style subscription verification (echoes hub.challenge). */
    public function verify(Request $request): mixed
    {
        $expected = config('services.whatsapp.verify_token');
        if ($expected && $request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === $expected) {
            return response($request->query('hub_challenge'), 200);
        }

        return response()->json(['ok' => true]);
    }

    /** POST — handle an inbound message. */
    public function handle(Request $request): JsonResponse
    {
        // Shared-secret gate (header or query). Only enforced when configured.
        $secret = config('services.whatsapp.webhook_token');
        if ($secret) {
            $given = $request->header('X-Webhook-Token') ?: $request->query('token');
            abort_unless(hash_equals((string) $secret, (string) $given), 401, 'Bad webhook token.');
        }

        $payload = $request->all();
        $phone = $this->extractPhone($payload);
        $text = $this->extractText($payload);

        if ($phone === null || $text === null || trim($text) === '') {
            return response()->json(['ok' => true, 'handled' => false, 'reason' => 'no message']);
        }

        $message = $this->support->inboundFromPhone($phone, $text);
        if ($message === null) {
            Log::info('Inbound WhatsApp from unknown number', ['phone' => $phone]);

            return response()->json(['ok' => true, 'handled' => false, 'reason' => 'unknown sender']);
        }

        return response()->json(['ok' => true, 'handled' => true, 'thread_id' => $message->thread_id]);
    }

    /** Pull the sender's number out of whichever shape the gateway sent. */
    protected function extractPhone(array $p): ?string
    {
        $candidates = [
            Arr::get($p, 'from'),
            Arr::get($p, 'sender'),
            Arr::get($p, 'number'),
            Arr::get($p, 'phone'),
            Arr::get($p, 'data.from'),
            Arr::get($p, 'data.number'),
            Arr::get($p, 'data.sender'),
            Arr::get($p, 'entry.0.changes.0.value.messages.0.from'),
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return $c;
            }
        }

        return null;
    }

    /** Pull the message body out of whichever shape the gateway sent. */
    protected function extractText(array $p): ?string
    {
        $candidates = [
            Arr::get($p, 'message'),
            Arr::get($p, 'text'),
            Arr::get($p, 'body'),
            Arr::get($p, 'text.body'),
            Arr::get($p, 'data.message'),
            Arr::get($p, 'data.text'),
            Arr::get($p, 'data.body'),
            Arr::get($p, 'entry.0.changes.0.value.messages.0.text.body'),
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return $c;
            }
        }

        return null;
    }
}
