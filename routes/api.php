<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\MemberBusinessController;
use App\Http\Controllers\Api\V1\MemberDocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ShopController;
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
    });
});
