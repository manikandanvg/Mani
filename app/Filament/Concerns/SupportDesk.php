<?php

namespace App\Filament\Concerns;

/**
 * Marks a screen as part of the support desk (Support & Track group): visible to
 * head-office staff AND the support persona, but never to dealer (distributor)
 * logins. Counterpart of HqOnly, which now also excludes support staff.
 */
trait SupportDesk
{
    protected static function supportDeskAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null
            && (! method_exists($user, 'isDistributor') || ! $user->isDistributor());
    }

    public static function canViewAny(): bool
    {
        return static::supportDeskAllowed();
    }

    public static function canAccess(): bool
    {
        return static::supportDeskAllowed();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::supportDeskAllowed();
    }
}
