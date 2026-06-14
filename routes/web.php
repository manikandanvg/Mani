<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\StorefrontController;
use App\Http\Middleware\SetLocale;
use App\Models\Bond;
use App\Services\Contract\ContractService;
use Illuminate\Support\Facades\Route;

// Razorpay subscription webhook (RD auto-debit / e-mandate). No auth; the payload is
// signature-verified in the controller and the route is CSRF-exempt (bootstrap/app.php).
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)->name('webhooks.razorpay');

// Admin: stream a bond's contract / MOU as a PDF (auth-guarded).
Route::get('/admin/contracts/{bond}/pdf', fn (Bond $bond) => app(ContractService::class)->stream($bond))
    ->middleware(['web', 'auth'])->name('contract.pdf');

// Admin: stream a redemption TAX INVOICE as a PDF (auth-guarded).
Route::get('/admin/redemptions/{invoice}/pdf', fn (\App\Models\RedemptionInvoice $invoice) => app(\App\Services\Redeem\RedemptionInvoicePdf::class)->stream($invoice))
    ->middleware(['web', 'auth'])->name('redemption.pdf');

// "View as Dealer" — super-admin impersonates a branch's distributor login, then steps back.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/admin/impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonate.leave');
    Route::get('/admin/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate.start');
});

// Public storefront (admin back-office is the Filament panel at /admin).
Route::middleware(SetLocale::class)->group(function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('home');
    Route::get('/shop', [StorefrontController::class, 'shop'])->name('shop');
    Route::get('/product/{id}', [StorefrontController::class, 'product'])->name('product');

    Route::get('/blog', fn () => app(StorefrontController::class)->posts('blog'))->name('blog');
    Route::get('/blog/{slug}', fn (string $slug) => app(StorefrontController::class)->postShow('blog', $slug))->name('blog.show');
    Route::get('/news', fn () => app(StorefrontController::class)->posts('news'))->name('news');
    Route::get('/news/{slug}', fn (string $slug) => app(StorefrontController::class)->postShow('news', $slug))->name('news.show');

    Route::get('/about', fn () => app(StorefrontController::class)->page('about'))->name('about');
    Route::get('/services', fn () => app(StorefrontController::class)->page('services'))->name('services');
    Route::get('/page/{slug}', [StorefrontController::class, 'page'])->name('page');
    Route::get('/faq', [StorefrontController::class, 'faq'])->name('faq');
    Route::get('/stores', [StorefrontController::class, 'stores'])->name('stores');
    Route::get('/contact', [StorefrontController::class, 'contact'])->name('contact');
    Route::post('/contact', [StorefrontController::class, 'contactSubmit'])->name('contact.submit');

    // cart + checkout
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart/add/{id}', [CartController::class, 'add'])->name('cart.add');
    Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
    Route::post('/cart/remove/{id}', [CartController::class, 'remove'])->name('cart.remove');
    Route::get('/checkout', [CartController::class, 'checkout'])->name('checkout');
    Route::post('/checkout', [CartController::class, 'placeOrder'])->name('checkout.place');
    Route::get('/order/{orderNo}/pay', [CartController::class, 'pay'])->name('order.pay');
    Route::post('/payment/verify', [CartController::class, 'verify'])->name('payment.verify');
    Route::get('/order/{orderNo}', [CartController::class, 'confirmation'])->name('order.confirmation');

    // locale + currency switch
    Route::get('/lang', [LocaleController::class, 'language'])->name('lang.switch');
    Route::get('/currency', [LocaleController::class, 'currency'])->name('currency.switch');
});
