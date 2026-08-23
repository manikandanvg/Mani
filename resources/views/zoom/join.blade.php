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

            // Plain-HTTP page (dev LAN / lordicl.test): browsers only honour
            // COOP/COEP in a SECURE context, so isolation can never happen here
            // no matter what the server sends. Name that directly — on the live
            // HTTPS domain this branch disappears.
            if (!window.isSecureContext) {
                handOff('The embedded player needs HTTPS.',
                    'page loaded over http:// — secure context required; opening the Zoom app instead');

                return;
            }

            // SharedArrayBuffer (gallery view, multi-stream video) exists only on a
            // cross-origin-isolated page. Android WebView never isolates — it has
            // no COOP support — so on the phone `crossOriginIsolated` is always
            // false even though this page sends COOP/COEP. The Zoom SDK still runs
            // without SAB in a reduced-video mode (2026-08-23: stop bailing out
            // here; only a real init/join failure hands off to the Zoom app).
            var isolated = !!window.crossOriginIsolated;
            if (!isolated) {
                diag.textContent = 'no SharedArrayBuffer on this device — reduced video mode';
            }

            if (typeof ZoomMtgEmbedded === 'undefined') {
                handOff(
                    window.__zoomLoadError || 'The meeting player failed to load.',
                    'sdk {{ $sdkVersion }}'
                );
                return;
            }

            // Watchdog: a WebView that silently never finishes init/join must not
            // strand the member on a spinner — hand off to the Zoom app instead.
            var joined = false;
            var watchdog = setTimeout(function () {
                if (!joined) handOff('The meeting player did not start in time.', 'join watchdog (25s) — ' + (isolated ? 'isolated' : 'no SAB'));
            }, 25000);

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
                    joined = true;
                    clearTimeout(watchdog);
                    boot.style.display = 'none';
                }).catch(function (e) {
                    clearTimeout(watchdog);
                    // Zoom returns {type, reason, errorCode} — keep the code, it is
                    // what their support asks for first. On a phone the player is
                    // optional: go to the Zoom app rather than show an error.
                    handOff(
                        (e && (e.reason || e.message)) || 'Could not join this meeting.',
                        e && e.errorCode ? 'zoom error ' + e.errorCode : ''
                    );
                });
            } catch (e) {
                clearTimeout(watchdog);
                handOff('The meeting player could not start.', (e && e.message) || '');
            }
        })();
    </script>
</body>
</html>
