<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

/**
 * Shared table filters (board 2026-08-11): financial registers open on the HQ
 * branch by default with a date range, switchable to any branch. Dealers are
 * already BranchScoped, so the branch picker hides for them.
 */
class CommonFilters
{
    public static function branch(string $column = 'branch_id'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Branch')
            ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
            ->default(auth()->user()?->isDistributor()
                ? null
                : (auth()->user()?->branch_id ?? \App\Services\SalesService::HQ_BRANCH_ID))
            ->visible(fn () => ! auth()->user()?->isDistributor());
    }

    public static function dateRange(string $column, string $label = 'Date'): Filter
    {
        return Filter::make($column . '_range')
            ->form([
                DatePicker::make('from')->label($label . ' from')->native(false),
                DatePicker::make('to')->label($label . ' to')->native(false),
            ])
            ->query(fn ($query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate($column, '>=', $v))
                ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate($column, '<=', $v)))
            ->indicateUsing(fn (array $data) => array_filter([
                ($data['from'] ?? null) ? 'From ' . $data['from'] : null,
                ($data['to'] ?? null) ? 'To ' . $data['to'] : null,
            ]));
    }
}
