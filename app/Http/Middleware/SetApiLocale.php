<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;

/**
 * App language (board 2026-08-12 app item 14): the mobile app sends its chosen
 * language as an X-Locale header (falling back to Accept-Language); every
 * Translatable::pick() in the request then resolves to that locale, so plan
 * names, ranks, CMS pages and notifications localize server-side.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next)
    {
        $requested = strtolower(substr((string) ($request->header('X-Locale') ?: $request->getPreferredLanguage() ?: ''), 0, 2));

        if ($requested !== '') {
            $active = once(fn () => Language::where('is_active', true)->pluck('code')->map(fn ($c) => strtolower($c))->all());
            if (in_array($requested, $active, true)) {
                app()->setLocale($requested);
            }
        }

        return $next($request);
    }
}
