<?php

namespace App\Support;

use App\Models\Language;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

/**
 * Helpers for translatable JSON columns ({"en":"..","ta":".."}). Global-scale:
 * one input per ACTIVE language, so content is authored in every locale.
 */
class Translatable
{
    /** @return array<string,string> code => label */
    public static function locales(): array
    {
        return once(fn () => Language::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name', 'code')
            ->all() ?: ['en' => 'English']);
    }

    public static function defaultLocale(): string
    {
        return once(fn () => Language::where('is_default', true)->value('code') ?? 'en');
    }

    /**
     * Resolve a translatable value to a display string. If the requested locale has no
     * authored value, fall back to the source value and machine-translate it (cached)
     * — so adding a language needs no manual content entry.
     *
     * Pass the value (NOT a live model property) — e.g. Translatable::pick($model->name).
     */
    public static function pick($value, ?string $locale = null): ?string
    {
        if (! is_array($value)) {
            return $value === null ? null : (string) $value;
        }

        $manager = app(\App\Services\Translation\TranslationManager::class);
        $locale ??= app()->getLocale();
        $source = $manager->source();

        // authored value for this locale wins
        if (! empty($value[$locale])) {
            return $value[$locale];
        }

        $src = $value[$source] ?? ($value['en'] ?? (array_values($value)[0] ?? null));
        if ($src === null || $src === '') {
            return $src;
        }

        return $locale === $source ? $src : $manager->to($src, $locale);
    }

    /** A fieldset of one input per locale, bound to "$field.$code". */
    public static function fieldset(string $field, string $label, bool $textarea = false, bool $requiredDefault = true): Component
    {
        $default = self::defaultLocale();
        $inputs = [];
        foreach (self::locales() as $code => $name) {
            $component = $textarea
                ? Textarea::make("{$field}.{$code}")->rows(3)
                : TextInput::make("{$field}.{$code}");
            $inputs[] = $component
                ->label("{$label} ({$name})")
                ->required($requiredDefault && $code === $default);
        }

        return Fieldset::make($label)->schema($inputs)->columns(2);
    }

    /** A fieldset of rich-text editors, one per locale, bound to "$field.$code". */
    public static function richFieldset(string $field, string $label): Component
    {
        $inputs = [];
        foreach (self::locales() as $code => $name) {
            $inputs[] = RichEditor::make("{$field}.{$code}")
                ->label("{$label} ({$name})")
                ->columnSpanFull();
        }

        return Fieldset::make($label)->schema($inputs)->columns(1);
    }

    /** A table column showing the default-locale value of a translatable field. */
    public static function column(string $field, string $label): TextColumn
    {
        $default = self::defaultLocale();

        return TextColumn::make($field)
            ->label($label)
            ->searchable()
            ->getStateUsing(function ($record) use ($field, $default) {
                $value = $record->{$field};
                if (is_array($value)) {
                    return $value[$default] ?? collect($value)->first();
                }
                return $value;
            });
    }
}
