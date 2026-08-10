<?php

namespace App\Filament\Concerns;

/**
 * Hides a Filament PAGE from the support-desk persona (support staff see only the
 * Support & Track group). Resources don't need this — BaseResource blocks support
 * globally — but pages extend Filament\Pages\Page directly, so each ops page that
 * dealers/admins use (Sales, Order Form, RD Collection, Redeem QR, Preferences,
 * reports) opts in via this trait.
 */
trait HiddenFromSupport
{
    public static function canAccess(): bool
    {
        return ! (auth()->user()?->isSupport() ?? true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! (auth()->user()?->isSupport() ?? true);
    }
}
