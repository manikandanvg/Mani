<?php

namespace App\Http\Controllers;

use App\Services\Phone\MissedCallVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Exotel "Passthru" applet target. Configure the Exophone's flow as: Passthru →
 * https://lordicl.com/webhooks/exotel/missed-call?key=<EXOTEL_WEBHOOK_KEY> → Hangup.
 * Exotel does not sign its calls, so the key in the URL is the shared secret. Exotel sends GET
 * with CallSid, CallFrom, CallTo, Direction, Created… A 200 lets the flow continue.
 */
class ExotelWebhookController extends Controller
{
    public function missedCall(Request $request, MissedCallVerification $svc): Response
    {
        $expected = (string) config('services.exotel.webhook_key');
        abort_unless($expected !== '' && hash_equals($expected, (string) $request->query('key', $request->input('key'))), 403, 'bad key');

        $row = $svc->webhook($request->all());

        return response($row ? 'verified' : 'ignored', 200)->header('Content-Type', 'text/plain');
    }
}
