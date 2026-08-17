<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\Zoom\ZoomSdkService;
use Illuminate\Http\Request;

/**
 * In-app Zoom join page (2026-08-12). The app opens this SIGNED URL inside a
 * WebView; the page loads Zoom's Web Meeting SDK (component view) and joins the
 * meeting with a server-signed SDK JWT. The display name rides in the signed
 * query, so the signature also pins who the link was minted for.
 */
class ZoomJoinController extends Controller
{
    public function show(Request $request, Meeting $meeting, ZoomSdkService $zoom)
    {
        abort_unless($zoom->configured(), 503, 'Zoom SDK is not configured.');
        abort_unless($meeting->is_published && filled($meeting->meeting_id), 404);

        $meetingNumber = preg_replace('/\D/', '', (string) $meeting->meeting_id);
        abort_if($meetingNumber === '', 404);

        return response()
            ->view('zoom.join', [
                'meeting' => $meeting,
                'meetingNumber' => $meetingNumber,
                'signature' => $zoom->signature($meetingNumber),
                'clientId' => $zoom->clientId(),
                'displayName' => (string) $request->query('name', 'LORDICL Member'),
                'sdkVersion' => (string) config('services.zoom.web_sdk_version', '6.2.0'),
                // Escape hatch shown on the page itself when the embedded player
                // cannot start. zoom.us/j hands off to the installed Zoom app and
                // falls back to Zoom's own web client when it is not installed —
                // so the member is never stranded on a dead screen.
                'nativeUrl' => 'https://zoom.us/j/' . $meetingNumber
                    . (filled($meeting->passcode) ? '?pwd=' . urlencode((string) $meeting->passcode) : ''),
            ])
            /*
             * Cross-origin isolation. The Web Meeting SDK decodes video through
             * WASM into a SharedArrayBuffer, and browsers only expose
             * SharedArrayBuffer on a cross-origin-isolated page. Without these
             * two headers `crossOriginIsolated` is false, the SDK throws while
             * initialising, and ZoomMtgEmbedded is never defined — which is
             * exactly the "could not load the meeting player" the app showed.
             *
             * require-corp means every subresource must opt in. This page loads
             * only source.zoom.us, which serves
             * `cross-origin-resource-policy: cross-origin`, so it stays loadable.
             * Anything added to this page later must carry CORP or CORS too.
             */
            ->header('Cross-Origin-Opener-Policy', 'same-origin')
            ->header('Cross-Origin-Embedder-Policy', 'require-corp');
    }
}
