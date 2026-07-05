<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MemberBusinessController;
use App\Http\Controllers\Api\V1\MemberDocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SupportController;
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
    Route::post('auth/otp/request', [OtpController::class, 'request']);
    Route::post('auth/otp/verify', [OtpController::class, 'verify']);

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

        // Cart checkout + orders (Phase 1).
        Route::post('checkout/quote', [OrderController::class, 'quote']);
        Route::post('checkout', [OrderController::class, 'checkout']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);

        // My Business — distributor area (Phase 2). Member-only (403 for customers).
        Route::get('member/dashboard', [MemberBusinessController::class, 'dashboard']);
        Route::get('member/downline', [MemberBusinessController::class, 'downline']);
        Route::get('member/earnings/summary', [MemberBusinessController::class, 'earningsSummaryEndpoint']);
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

        // Live & Learn (Phase 6a) — scheduled meetings (Zoom deep-link).
        Route::get('meetings', [MeetingController::class, 'index']);

        // Training library (Phase 6b) — browse public drive folders/files (member-only).
        Route::get('library', [LibraryController::class, 'index']);
    });
});
