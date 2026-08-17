<?php

namespace App\Filament\Resources\StockResource\Pages;

use App\Filament\Resources\StockResource;
use App\Models\Stock;
use App\Support\Translatable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    protected static ?string $title = 'Stock';

    /**
     * Low-stock alert (board 2026-08-13): when any product in the visible scope has
     * fallen to or below its minimum, a danger notification pops up naming the worst
     * offenders, with a link that filters the table down to just those rows.
     */
    public function mount(): void
    {
        parent::mount();

        $default = Translatable::defaultLocale();

        // Same branch scoping the table applies, so a dealer only sees their own.
        $low = static::getResource()::getEloquentQuery()
            ->low()
            ->with(['branch', 'catalogProduct'])
            ->orderBy('quantity')
            ->limit(6)
            ->get();

        if ($low->isEmpty()) {
            return;
        }

        $total = static::getResource()::getEloquentQuery()->low()->count();

        $lines = $low->map(function (Stock $s) use ($default) {
            $name = $s->catalogProduct
                ? Translatable::pick($s->catalogProduct->name, $default)
                : ($s->catalogProduct?->code ?? 'Item');

            return sprintf(
                '• %s — %s left (min %s)%s',
                $name,
                rtrim(rtrim(number_format((float) $s->quantity, 4), '0'), '.'),
                rtrim(rtrim(number_format((float) $s->min_qty, 4), '0'), '.'),
                $s->branch ? ' · ' . $s->branch->name : '',
            );
        })->implode('<br>');

        Notification::make()
            ->title($total === 1 ? '1 product is at its minimum stock' : "{$total} products are at minimum stock")
            ->body($lines . ($total > $low->count() ? '<br><em>…and ' . ($total - $low->count()) . ' more</em>' : ''))
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->persistent()
            ->send();
    }
}
