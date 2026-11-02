<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // On-the-fly translation. driver: echo | mymemory (keyless) | google | libre
    'translation' => [
        'driver' => env('TRANSLATE_DRIVER', 'mymemory'),
        'source' => env('TRANSLATE_SOURCE', 'en'),
        'key' => env('TRANSLATE_KEY'),
        'endpoint' => env('TRANSLATE_ENDPOINT'),   // for libre
        'email' => env('TRANSLATE_EMAIL'),          // raises MyMemory free quota
    ],

    // Razorpay payment gateway. Key id is public (Checkout JS); secret stays server-side.
    // Zoom Meeting SDK (in-app meeting join, 2026-08-12) — General App credentials
    // from marketplace.zoom.us; the secret signs SDK JWTs server-side only.
    'zoom' => [
        'sdk_client_id' => env('ZOOM_SDK_CLIENT_ID'),
        'sdk_client_secret' => env('ZOOM_SDK_CLIENT_SECRET'),
        'web_sdk_version' => env('ZOOM_WEB_SDK_VERSION', '6.2.0'),
        // Server-to-Server OAuth app (board phase-1, 2026-08-21): lets the admin
        // panel create the meeting AT Zoom when one is saved here — no parallel
        // manual entry on the Zoom portal. Needs a Business/Pro Zoom plan.
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        // which licensed user hosts auto-created meetings ('me' = the app owner)
        'host' => env('ZOOM_HOST_USER', 'me'),
        // "Secret Token" from the app's Feature → Event Subscriptions page;
        // validates the attendance webhooks (participant joined/left).
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
    ],

    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    // Geocoding (address -> lat/long for the store locator). driver: nominatim (free) | google
    'geocode' => [
        'driver' => env('GEOCODE_DRIVER', 'nominatim'),
        'endpoint' => env('GEOCODE_ENDPOINT'),    // override Nominatim host if self-hosted
        'key' => env('GEOCODE_KEY'),              // google
        'country' => env('GEOCODE_COUNTRY', 'in'),
        'user_agent' => env('GEOCODE_UA', 'LordICL Store Locator (admin@lordicl.com)'),
    ],

    // WhatsApp gateway (legacy tbl_whatsappapi). Defaults are the crawled production
    // values; override per-environment via .env. number = country code + local number.
    // Go-live 2026-11-01. The static password the board asked for on "Generate cut-off"
    // (a second accidental click in a month is also refused by the run-per-month guard).
    // It lives ONLY in .env (PAYOUT_CUTOFF_PASSWORD) — never in the repository; with no
    // value set the button refuses to run. Also where the LEGACY site now lives:
    // lordicl.com/app/* (the old Android app's host) is forwarded there.
    'payout' => [
        'cutoff_password' => env('PAYOUT_CUTOFF_PASSWORD'),
    ],
    'legacy_site' => [
        'url' => rtrim(env('LEGACY_SITE_URL', 'https://old.lordicl.com'), '/'),
    ],

    // Exotel cloud telephony — missed-call number verification (board 2026-11-02: no OTP of any
    // kind for the trusted-device flow). The distributor miss-calls the virtual number; Exotel's
    // Passthru applet calls our webhook with the caller number. Region Singapore → api.exotel.com,
    // Mumbai → api.in.exotel.com.
    'exotel' => [
        'sid' => env('EXOTEL_SID'),
        'api_key' => env('EXOTEL_API_KEY'),
        'api_token' => env('EXOTEL_API_TOKEN'),
        'subdomain' => env('EXOTEL_SUBDOMAIN', 'api.exotel.com'),
        'virtual_number' => env('EXOTEL_VIRTUAL_NUMBER'),          // the Exophone distributors miss-call
        'webhook_key' => env('EXOTEL_WEBHOOK_KEY'),                 // ?key=… on the Passthru URL (Exotel does not sign)
        'verification_minutes' => (int) env('EXOTEL_VERIFICATION_MINUTES', 10),
    ],

    'whatsapp' => [
        'url' => env('WHATSAPP_URL', 'https://whatsapp.lordicl.com/'),
        'instance_id' => env('WHATSAPP_INSTANCE_ID', '69F89E4E9A333'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN', '693877c69f935'),
        'country_code' => env('WHATSAPP_COUNTRY_CODE', '91'),
        // TEST GUARD: while set, every outgoing message is redirected to this number
        // instead of the real customer — so testing never messages real members.
        // Clear WHATSAPP_TEST_RECIPIENT in production .env to send to actual customers.
        'test_recipient' => env('WHATSAPP_TEST_RECIPIENT'),
        // Inbound webhook (Phase 4c-2): shared secret the gateway must present
        // (header X-Webhook-Token or ?token=). When set, requests without it are
        // rejected. `verify_token` echoes a Meta-style subscription challenge.
        'webhook_token' => env('WHATSAPP_WEBHOOK_TOKEN'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    // Foreign-exchange rates. driver: open_er_api (free, keyless) | manual (no auto-fetch).
    // Updates currencies.rate_to_base; metal rates stay admin-entered.
    'exchange' => [
        'driver' => env('EXCHANGE_DRIVER', 'open_er_api'),
        'key' => env('EXCHANGE_KEY'),   // reserved for keyed providers (e.g. exchangerate-api)
    ],

    // Mobile app store links (footer badges). Leave unset to show a non-linking badge.
    'app' => [
        'android' => env('APP_ANDROID_URL', '#'),
        'ios' => env('APP_IOS_URL', '#'),
    ],

    // Firebase Cloud Messaging (HTTP v1) for app push (Phase 4b). Service-account creds.
    // Until `enabled` + all three creds are set, PushSender no-ops (inbox still works).
    // FCM_PRIVATE_KEY: paste the service-account private_key (with literal \n escapes).
    'fcm' => [
        'enabled' => env('FCM_ENABLED', false),
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => env('FCM_PRIVATE_KEY'),
    ],

    // Native Apple Push Notification service (board 2026-09-21): iPhones register an
    // APNs device token (provider=apns) and ApnsSender posts to Apple directly with a
    // .p8 auth key (Apple Developer → Keys). APNS_SANDBOX=true only for Xcode
    // development builds; TestFlight and App Store builds use production.
    'apns' => [
        'enabled' => env('APNS_ENABLED', false),
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.app.lordicl'),
        'private_key' => env('APNS_PRIVATE_KEY'),
        'sandbox' => env('APNS_SANDBOX', false),
    ],

    // Aadhaar verification. driver: checksum (instant, offline Verhoeff) | (future: sandbox OTP e-KYC)
    'aadhaar' => [
        'driver' => env('AADHAAR_DRIVER', 'checksum'),
    ],

    // PAN verification. driver: fake | sandbox (sandbox.co.in) | surepass
    'pan' => [
        'driver' => env('PAN_DRIVER', 'fake'),
        'endpoint' => env('PAN_ENDPOINT', 'https://api.sandbox.co.in'),
        'token' => env('PAN_TOKEN'),         // surepass bearer token
        'key' => env('PAN_KEY'),             // sandbox x-api-key
        'secret' => env('PAN_SECRET'),       // sandbox x-api-secret
        'version' => env('PAN_VERSION', '1.0'),
        // grand-total at/above which a PAN name match is mandatory (Rule 114B / 269ST)
        'block_threshold' => env('PAN_BLOCK_THRESHOLD', 200000),
    ],

    // Store-review demo logins (Play / App Store reviewers, 2026-10-25): "phone:code,phone:code".
    // Only the listed demo phones get a fixed OTP (OtpService); leave empty on dev.
    'review_logins' => env('REVIEW_LOGINS', ''),

    // Google Play Developer API (board 2026-10-31 item 14: Play reviews → testimonials).
    // A service account invited in Play Console (Users & permissions → "View app
    // information", "Reply to reviews"); its JSON key file path + the app package name.
    'google_play' => [
        'package' => env('GOOGLE_PLAY_PACKAGE', 'com.app.lordicl'),
        'service_account_json' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON'),
    ],

];
