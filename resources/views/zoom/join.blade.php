<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{{ $meeting->title }} — LORDICL</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; background: #1c1817; font-family: sans-serif; }
        #meetingSDKElement { height: 100%; }
        .boot { position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; color: #fff; text-align: center; padding: 24px; }
        .boot .spin { width: 34px; height: 34px; border: 3px solid rgba(255,255,255,0.25); border-top-color: #e9c46a; border-radius: 50%; animation: r 0.9s linear infinite; }
        @keyframes r { to { transform: rotate(360deg); } }
        .boot small { color: rgba(255,255,255,0.6); }
        .err { color: #ffb4a8; }
        .fallback { display: none; margin-top: 6px; padding: 12px 22px; border: 0; border-radius: 999px;
                    background: #ab222f; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; }
        .diag { color: rgba(255,255,255,0.35); font-size: 11px; font-family: monospace; }
    </style>
</head>
<body>
    <div id="meetingSDKElement"></div>
    <div class="boot" id="boot">
        <div class="spin"></div>
        <div><strong>{{ $meeting->title }}</strong></div>
        <small id="status">Connecting you to the meeting…</small>
        <small id="err" class="err"></small>
        <a id="fallback" class="fallback" href="{{ $nativeUrl }}">Open in the Zoom app</a>
        <small id="diag" class="diag"></small>
    </div>

    <script>
        // Captured BEFORE the SDK loads so a load/parse failure is recorded with
        // its real reason. Every failure used to collapse into one generic
        // sentence, which made a missing-header problem look like a dead CDN.
        window.__zoomLoadError = null;
        window.addEventListener('error', function (e) {
            if (e && e.target && e.target.tagName === 'SCRIPT') {
                window.__zoomLoadError = 'SDK script did not load (network or CDN blocked).';
            }
        }, true);
    </script>
    <script src="https://source.zoom.us/{{ $sdkVersion }}/zoom-meeting-embedded-{{ $sdkVersion }}.min.js"></script>
    <script>
        (function () {
            var boot = document.getElementById('boot');
            var err = document.getElementById('err');
            var diag = document.getElementById('diag');
            var status = document.getElementById('status');
            var fallback = document.getElementById('fallback');

            var NATIVE_URL = @json($nativeUrl);

            /** Stop pretending to connect; say why and offer the native app. */
            function fail(message, detail) {
                status.textContent = 'Could not start the meeting here.';
                err.textContent = message;
                diag.textContent = detail || '';
                fallback.style.display = 'inline-block';
                document.querySelector('.spin').style.display = 'none';
            }

            /**
             * The embedded player is optional; being in the meeting is not. When
             * the player cannot run, send the member straight to the Zoom app
             * rather than parking them on an error they have to interpret. The
             * button stays visible in case the hand-off is blocked.
             */
            function handOff(reason, detail) {
                status.textContent = 'Opening the Zoom app…';
                err.textContent = reason;
                diag.textContent = detail || '';
                fallback.style.display = 'inline-block';
                setTimeout(function () { window.location.href = NATIVE_URL; }, 1200);
            }

            // The SDK decodes video through WASM into a SharedArrayBuffer, which
            // browsers expose only on a cross-origin-isolated page.
            //
            // Two very different causes look identical from here — the server not
            // sending COOP/COEP, or a browser that refuses to isolate even when it
            // does (Android WebView is inconsistent about this, and Zoom does not
            // support WebView for the Web SDK). Re-fetch this same page and read
            // the headers back so the screen states which one it actually is
            // instead of guessing, then hand off either way.
            if (!window.crossOriginIsolated) {
                handOff('This device cannot run the meeting player.', 'checking why…');

                fetch(window.location.href, { method: 'GET', cache: 'no-store' }).then(function (r) {
                    var coop = r.headers.get('cross-origin-opener-policy');
                    var coep = r.headers.get('cross-origin-embedder-policy');
                    diag.textContent = (coop && coep)
                        ? 'COOP/COEP present (' + coop + ' / ' + coep + ') — this browser refuses to isolate'
                        : 'server sent no COOP/COEP (coop=' + coop + ' coep=' + coep + ')';
                }).catch(function (e) {
                    diag.textContent = 'header probe failed: ' + ((e && e.message) || 'unknown');
                });

                return;
            }

            if (typeof ZoomMtgEmbedded === 'undefined') {
                handOff(
                    window.__zoomLoadError || 'The meeting player failed to load.',
                    'sdk {{ $sdkVersion }}'
                );
                return;
            }

            try {
                var client = ZoomMtgEmbedded.createClient();
                client.init({
                    zoomAppRoot: document.getElementById('meetingSDKElement'),
                    language: 'en-US',
                    customize: {
                        video: { isResizable: false, viewSizes: { default: { width: window.innerWidth, height: window.innerHeight } } },
                    },
                }).then(function () {
                    return client.join({
                        signature: @json($signature),
                        sdkKey: @json($clientId),
                        meetingNumber: @json($meetingNumber),
                        password: @json((string) ($meeting->passcode ?? '')),
                        userName: @json($displayName),
                    });
                }).then(function () {
                    boot.style.display = 'none';
                }).catch(function (e) {
                    // Zoom returns {type, reason, errorCode} — keep the code, it is
                    // what their support asks for first.
                    fail(
                        (e && (e.reason || e.message)) || 'Could not join this meeting.',
                        e && e.errorCode ? 'zoom error ' + e.errorCode : ''
                    );
                });
            } catch (e) {
                fail('The meeting player could not start.', (e && e.message) || '');
            }
        })();
    </script>
</body>
</html>
