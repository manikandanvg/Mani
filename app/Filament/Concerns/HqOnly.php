<?php

namespace App\Filament\Concerns;

/**
 * Marks a resource OR page as Head-Office only. Distributors who log into the admin
 * panel as "semi-admins" never see it in navigation, and direct-URL access is rejected.
 * Mirrors the legacy sidebar split `if ($admin_id == 1) { full } else { branch }`.
 *
 * Works for both Filament Resources (which authorize via canViewAny) and Pages
 * (which authorize via canAccess).
 */
trait HqOnly
{
    protected static function hqOnlyAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null
            && (! method_exists($user, 'isDistributor') || ! $user->isDistributor())
            && (! method_exists($user, 'isSupport') || ! $user->isSupport());
    }

    public static function canViewAny(): bool
    {
        return static::hqOnlyAllowed();
    }

    public static function canAccess(): bool
    {
        return static::hqOnlyAllowed();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::hqOnlyAllowed();
    }
}
