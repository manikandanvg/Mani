<?php

use App\Http\Controllers\Api\Device\LboxController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\DigiGoldController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MemberBusinessController;
use App\Http\Controllers\Api\V1\MemberDocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Models\LiveRate;
use Illuminate\Support\Facades\Route;

/*
| Mobile app API (consumed by the React Native app at D:\lordapp).
| Versioned under /api/v1. Token auth via Sanctum personal-access tokens.
| See docs/mobile-app-rebuild-report.md.
*/

Route::prefix('v1')->group(function () {
    // --- Public ---
    Route::post('auth/otp/request', [OtpController::class, 'request'])->middleware('throttle:otp-request');
    Route::post('auth/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify');

    // Live metal rate (India) — the app's canonical first call.
    Route::get('rates', function () {
        $rate = LiveRate::latestFor('IN');

        return response()->json([
            'gold' => (float) ($rate->gold ?? 0),
            'silver' => (float) ($rate->silver ?? 0),
            'diamond' => (float) ($rate->diamond ?? 0),
            'effective_at' => optional($rate?->effective_at)->toIso8601String(),
        ]);
    });

    // CMS pages (board 2026-08-11 item 11): the app's Legal/About pop-ups load
    // straight from the database — same content the storefront renders.
    Route::get('pages', function () {
        return response()->json([
            'data' => \App\Models\CmsPage::published()->orderBy('slug')->get()
                ->map(fn ($p) => [
                    'slug' => $p->slug,
                    'title' => \App\Support\Translatable::pick($p->title),
                ])->values(),
        ]);
    });
    Route::get('pages/{slug}', function (string $slug) {
        $page = \App\Models\CmsPage::published()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'slug' => $page->slug,
            'title' => \App\Support\Translatable::pick($page->title),
            'body' => \App\Support\Translatable::pick($page->body),   // HTML
        ]);
    });

    // App preferences meta (2026-08-12 item 14): the language + currency options the
    // "My Preferences" screen offers, straight from the admin-end modules.
    Route::get('meta/preferences', function () {
        return response()->json([
            'languages' => \App\Models\Language::where('is_active', true)->orderBy('sort')->get()
                ->map(fn ($l) => [
                    'code' => strtolower($l->code),
                    'name' => $l->name,
                    'native_name' => $l->native_name,
                    'default' => (bool) $l->is_default,
                ])->values(),
            'currencies' => \App\Models\Currency::where('is_active', true)->orderBy('code')->get()
                ->map(fn ($c) => [
                    'code' => strtoupper($c->code),
                    'symbol' => $c->symbol,
                    'name' => $c->name,
                    'rate_to_base' => (float) $c->rate_to_base,   // 1 INR = rate units
                    'decimals' => (int) ($c->decimals ?? 2),
                    'base' => (bool) $c->is_base,
                ])->values(),
            'base_currency' => strtoupper(\App\Support\Money::base()?->code ?? 'INR'),
        ]);
    });

    // Home utilities (board 2026-08-11 items 8.3–8.5) — public, legacy parity.
    Route::get('price-list', [\App\Http\Controllers\Api\V1\UtilityController::class, 'priceList']);
    Route::get('calculator/plans', [\App\Http\Controllers\Api\V1\UtilityController::class, 'calculatorPlans']);
    Route::post('calculator', [\App\Http\Controllers\Api\V1\UtilityController::class, 'calculator']);
    Route::get('stores', [\App\Http\Controllers\Api\V1\UtilityController::class, 'stores']);
    Route::get('posts', [\App\Http\Controllers\Api\V1\UtilityController::class, 'posts']);   // Events & News (item 20)

    // Shop catalog (Phase 1).
    Route::get('categories', [ShopController::class, 'categories']);
    Route::get('products', [ShopController::class, 'products']);
    Route::get('products/{product}', [ShopController::class, 'product']);

    // Document vault download (Phase 2b) — authenticated by URL signature, not a token,
    // so the app can open the PDF directly in a viewer.
    Route::get('member/documents/{type}/{id}', [MemberDocumentController::class, 'download'])
        ->middleware('signed')->name('api.member.document');

    // Inbound WhatsApp (Phase 4c-2) — gateway → support thread. Auth via shared secret,
    // not a user token. GET verifies a Meta-style subscription; POST handles messages.
    Route::get('webhooks/whatsapp', [WhatsappWebhookController::class, 'verify']);
    Route::post('webhooks/whatsapp', [WhatsappWebhookController::class, 'handle']);

    // Training library download (Phase 6b) — URL-signature authed so the app opens
    // the file directly in a viewer, no bearer token needed.
    Route::get('library/files/{id}', [LibraryController::class, 'download'])
        ->middleware('signed')->name('api.library.file');

    // Payslip PDF — URL-signature authed (employee id baked into the signature).
    Route::get('member/payslips/{id}/pdf', [AttendanceController::class, 'payslipPdf'])
        ->middleware('signed')->name('api.member.payslip');

    // --- Authenticated (member token) ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AccountController::class, 'me']);
        Route::post('logout', [AccountController::class, 'logout']);
        // Multi-account holders (board 2026-08-11): list + swap accounts on one phone.
        Route::get('me/accounts', [AccountController::class, 'accounts']);
        Route::post('me/switch', [AccountController::class, 'switchAccount']);

        // Cart checkout + orders (Phase 1).
        Route::post('checkout/quote', [OrderController::class, 'quote']);
        Route::post('checkout', [OrderController::class, 'checkout']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        // Native in-app payment (2026-08-24): Razorpay Checkout options + JSON verify.
        Route::post('orders/{order}/payment/intent', [PaymentController::class, 'orderIntent']);
        Route::post('orders/{order}/payment/verify', [PaymentController::class, 'orderVerify']);

        // My Business — distributor area (Phase 2). Member-only (403 for customers).
        Route::get('member/dashboard', [MemberBusinessController::class, 'dashboard']);
        Route::get('member/status', [MemberBusinessController::class, 'status']);
        Route::get('member/downline', [MemberBusinessController::class, 'downline']);
        Route::get('member/genealogy', [MemberBusinessController::class, 'genealogy']);
        // Monthly Tasks (board 2026-08-29): My Status → tasks, stock chart, proof submissions.
        Route::get('member/tasks', [\App\Http\Controllers\Api\V1\TaskController::class, 'index']);
        Route::get('member/tasks/stock-chart', [\App\Http\Controllers\Api\V1\TaskController::class, 'stockChart']);
        Route::post('member/tasks/{assignment}/submit', [\App\Http\Controllers\Api\V1\TaskController::class, 'submit']);
        // Install L-BOX (board 2026-08-23 items 1-5): the phone bridges a box over BLE.
        Route::get('member/lbox/devices', [\App\Http\Controllers\Api\V1\LboxInstallController::class, 'index']);
        Route::post('member/lbox/install/start', [\App\Http\Controllers\Api\V1\LboxInstallController::class, 'start']);
        Route::get('member/lbox/devices/{device}/status', [\App\Http\Controllers\Api\V1\LboxInstallController::class, 'status']);
        Route::post('member/lbox/devices/{device}/complete', [\App\Http\Controllers\Api\V1\LboxInstallController::class, 'complete']);
        Route::post('member/lbox/devices/{device}/wifi', [\App\Http\Controllers\Api\V1\LboxInstallController::class, 'wifi']);
        Route::get('member/earnings/summary', [MemberBusinessController::class, 'earningsSummaryEndpoint']);
        Route::get('member/earnings/gap-details', [MemberBusinessController::class, 'gapDetails']);
        Route::get('member/earnings', [MemberBusinessController::class, 'earnings']);
        Route::get('member/bonds', [MemberBusinessController::class, 'bonds']);

        // Document vault (Phase 2b) — list returns short-lived signed download URLs.
        Route::get('member/documents', [MemberDocumentController::class, 'index']);

        // Inbox & Comms (Phase 4a) — unified message center for members AND customers.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Push registration (Phase 4b) — FCM device tokens, member or customer.
        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        // Support chat (Phase 4c) — app ⇄ HQ, member or customer.
        Route::get('support/threads', [SupportController::class, 'index']);
        Route::post('support/threads', [SupportController::class, 'store']);
        Route::get('support/unread-count', [SupportController::class, 'unreadCount']);
        Route::get('support/threads/{thread}', [SupportController::class, 'show']);
        Route::post('support/threads/{thread}/messages', [SupportController::class, 'reply']);

        // Community (Phase 5a/5b/5c) — announcements + team feed + engagement + leaderboards.
        Route::get('community/feed', [CommunityController::class, 'feed']);
        Route::get('community/leaderboard', [CommunityController::class, 'leaderboard']);
        Route::get('community/champions', [CommunityController::class, 'champions']);   // item 19
        Route::post('community/posts', [CommunityController::class, 'store']);          // member posts
        Route::get('community/posts/{post}', [CommunityController::class, 'show']);
        Route::delete('community/posts/{post}', [CommunityController::class, 'destroy']); // own post

        Route::post('community/posts/{post}/like', [CommunityController::class, 'toggleLike']);
        Route::get('community/posts/{post}/comments', [CommunityController::class, 'comments']);
        Route::post('community/posts/{post}/comments', [CommunityController::class, 'addComment']);

        // Payroll attendance (2026-07 board mandate) — employee-enrolled distributors only.
        Route::get('member/attendance', [AttendanceController::class, 'index']);
        Route::post('member/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('member/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('member/payslips', [AttendanceController::class, 'payslips']);
        Route::get('member/leave-requests', [AttendanceController::class, 'leaves']);
        Route::post('member/leave-requests', [AttendanceController::class, 'storeLeave']);

        // Closed-wallet withdrawal via the branch L-BOX static QR (no gateway).
        Route::get('member/wallet/qr/{uuid}', [WalletController::class, 'resolveQr']);
        Route::post('member/wallet/withdraw', [WalletController::class, 'withdraw']);
        Route::get('member/wallet/withdrawals', [WalletController::class, 'withdrawals']);

        // Digi Market (board 2026-08-11) — gold + silver investment purses.
        // Buy by amount/weight (wallet or online funding); withdraw metal → cash
        // wallet minus the platform fee. Scan & Pay from metal is retired.
        Route::get('digimarket', [DigiGoldController::class, 'summary']);
        Route::get('digimarket/history', [DigiGoldController::class, 'history']);
        Route::post('digimarket/quote', [DigiGoldController::class, 'quote']);
        Route::post('digimarket/buy', [DigiGoldController::class, 'buy']);
        Route::post('digimarket/withdraw', [DigiGoldController::class, 'withdraw']);
        Route::get('digimarket/purchases/{purchase}', [DigiGoldController::class, 'purchase']);
        Route::post('digimarket/purchases/{purchase}/verify', [PaymentController::class, 'digiVerify']);

        // Live & Learn (Phase 6a) — scheduled meetings (Zoom deep-link).
        Route::get('meetings', [MeetingController::class, 'index']);
        // Attendance (board phase-1, 2026-08-21): join-tap log + the member's own
        // attended-meetings list for the Training Library.
        Route::post('meetings/{meeting}/joined', [MeetingController::class, 'joined']);
        // Native Meeting SDK join (2026-08-24): server-signed SDK JWT + join params.
        Route::get('meetings/{meeting}/sdk-token', [MeetingController::class, 'sdkToken']);
        Route::get('member/meeting-attendance', [MeetingController::class, 'myAttendance']);

        // Training library (Phase 6b) — browse public drive folders/files (member-only).
        Route::get('library', [LibraryController::class, 'index']);

        // My Profile + photo upload (item 21) and the mobile device registry (16/17b).
        Route::get('member/profile', [\App\Http\Controllers\Api\V1\ProfileController::class, 'show']);
        Route::post('member/profile', [\App\Http\Controllers\Api\V1\ProfileController::class, 'update']);
        Route::post('member/profile/photo', [\App\Http\Controllers\Api\V1\ProfileController::class, 'photo']);
        Route::post('device-registry', [\App\Http\Controllers\Api\V1\ProfileController::class, 'registerDevice']);

        // Re-KYC (item 18): PAN digital, Aadhaar upload → manual approval.
        Route::get('member/kyc', [\App\Http\Controllers\Api\V1\KycController::class, 'status']);
        Route::post('member/kyc/pan', [\App\Http\Controllers\Api\V1\KycController::class, 'verifyPan']);
        Route::post('member/kyc/aadhaar', [\App\Http\Controllers\Api\V1\KycController::class, 'submitAadhaar']);
        Route::post('member/kyc/aadhaar/otp', [\App\Http\Controllers\Api\V1\KycController::class, 'sendAadhaarOtp']);
        Route::post('member/kyc/aadhaar/otp/verify', [\App\Http\Controllers\Api\V1\KycController::class, 'verifyAadhaarOtp']);
    });
});

/*
| L-BOX device API — the smart branch box (internal-only hardware). Bearer token
| issued at pairing; register itself is authed by serial + one-time pairing code.
*/
Route::prefix('device/v1')->group(function () {
    Route::post('register', [LboxController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('heartbeat', [LboxController::class, 'heartbeat']);
        Route::post('attendance', [LboxController::class, 'attendance']);
        Route::get('announcements', [LboxController::class, 'announcements']);
        Route::post('announcements/ack', [LboxController::class, 'ackAnnouncements']);
        Route::get('ota/check', [LboxController::class, 'otaCheck']);
        Route::get('ota/download/{firmware}', [LboxController::class, 'otaDownload']);
        Route::post('ai/ask', [LboxController::class, 'aiAsk']);
        Route::post('ai/voice', [LboxController::class, 'aiVoice']);
    });
});
