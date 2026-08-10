<?php

namespace App\Filament;

use App\Filament\Concerns\TranslatesNavigation;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * Shared base for every Filament resource. Adds locale-aware navigation + model
 * labels (breadcrumbs, page titles, record actions) on top of the framework's
 * Resource. Field/column/section labels are translated globally in AppServiceProvider.
 *
 * Labels are derived from the English model name and translated once — we deliberately
 * do NOT call Filament's parent label methods, because their internal pluralizer would
 * run on the already-translated (Tamil) string and corrupt it.
 */
abstract class BaseResource extends Resource
{
    use TranslatesNavigation;

    /**
     * Support-desk staff see ONLY the Support & Track screens. Blocking here (the
     * shared base) hides every ordinary resource from the support persona; the few
     * screens support may use (tickets, support chat) override these methods.
     * Subclasses using HqOnly define their own copies, which also exclude support.
     */
    public static function canViewAny(): bool
    {
        if (auth()->user()?->isSupport()) {
            return false;
        }

        return parent::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->user()?->isSupport()) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }

    public static function getModelLabel(): string
    {
        if (filled(static::$modelLabel)) {
            return __(static::$modelLabel);
        }

        return __((string) str(class_basename(static::getModel()))->headline());
    }

    public static function getPluralModelLabel(): string
    {
        if (filled(static::$pluralModelLabel)) {
            return __(static::$pluralModelLabel);
        }

        return __((string) Str::of(class_basename(static::getModel()))->headline()->plural());
    }

    public static function getNavigationLabel(): string
    {
        // Explicit label wins; otherwise reuse the (already-translated) plural — never
        // re-headline it, or a multi-word Tamil label gets mangled.
        return filled(static::$navigationLabel)
            ? __(static::$navigationLabel)
            : static::getPluralModelLabel();
    }
}
