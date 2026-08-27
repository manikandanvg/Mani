<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Razorpay posts webhooks without a CSRF token; authenticity is verified by HMAC signature.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        // Mobile app language: X-Locale header → app locale for every API response.
        $middleware->api(append: [\App\Http\Middleware\SetApiLocale::class]);
        // Guests hitting an auth-guarded admin route (receipt / PDF streams) go to the
        // panel login — there is no plain "login" route, so the default would throw.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
