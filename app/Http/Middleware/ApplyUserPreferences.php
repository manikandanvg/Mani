<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;

/**
 * Back-office localization: apply the authenticated user's saved locale + display
 * currency (set on Settings → My Preferences) to every panel request. The storefront
 * has its own session-based SetLocale; this is the logged-in equivalent for the panel.
 */
class ApplyUserPreferences
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $active = once(fn () => Language::where('is_active', true)->pluck('code')->all());

            if ($user->locale && in_array($user->locale, $active, true)) {
                app()->setLocale($user->locale);
                session(['locale' => $user->locale]);
            }

            if ($user->currency_code) {
                session(['currency' => $user->currency_code]);
            }
        }

        return $next($request);
    }
}
