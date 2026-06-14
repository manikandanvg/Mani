<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;

/**
 * Storefront localization: use the visitor's chosen locale (session), else fall back
 * to the site default. Only active languages are honoured; English is the fallback.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $active = once(fn () => Language::where('is_active', true)->pluck('code')->all());
        $locale = session('locale');

        if (! $locale || ! in_array($locale, $active)) {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
