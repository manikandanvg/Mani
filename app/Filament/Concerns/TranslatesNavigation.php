<?php

namespace App\Filament\Concerns;

/**
 * Routes a resource/page's navigation label and group through Laravel's translator
 * so the sidebar follows the active locale (see lang/ta.json). English is the source
 * key; an untranslated string simply falls back to itself.
 */
trait TranslatesNavigation
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): ?string
    {
        $group = parent::getNavigationGroup();

        return $group ? __($group) : $group;
    }
}
