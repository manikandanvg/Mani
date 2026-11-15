<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

/**
 * Shared table filters (board 2026-08-11): financial registers open on the HQ
 * branch by default with a date range, switchable to any branch. Dealers are
 * already BranchScoped, so the branch picker hides for them.
 *
 * Board 2026-11-15: every branch dropdown is searchable and shows the FULL branch name —
 * "Branch name (in-charge / city / phone)" — because 1,180 branches share a handful of names.
 */
class CommonFilters
{
    public static function branch(string $column = 'branch_id', bool $defaultHq = true): SelectFilter
    {
        $filter = SelectFilter::make($column)
            ->label('Branch')
            ->visible(fn () => ! auth()->user()?->isDistributor());

        if ($defaultHq) {
            $filter->default(auth()->user()?->isDistributor()
                ? null
                : (auth()->user()?->branch_id ?? \App\Services\SalesService::HQ_BRANCH_ID));
        }

        return static::fullBranchLabels($filter);
    }

    /** Make any branch SelectFilter searchable by name / in-charge / city / phone, with the full label. */
    public static function fullBranchLabels(SelectFilter $filter): SelectFilter
    {
        if ($filter->queriesRelationships()) {
            return $filter
                ->getOptionLabelFromRecordUsing(fn (Branch $record) => $record->pickLabel())
                ->searchable(['name', 'incharge', 'city', 'phone'])
                ->preload(false)
                ->indicateUsing(fn (array $state) => static::branchIndicator($filter, $state));
        }

        return $filter
            ->options(fn () => Branch::pickOptions(null, 50))
            ->getSearchResultsUsing(fn (string $search) => Branch::pickOptions($search))
            ->getOptionLabelUsing(fn ($value) => Branch::find($value)?->pickLabel())
            ->searchable()
            ->indicateUsing(fn (array $state) => static::branchIndicator($filter, $state));
    }

    protected static function branchIndicator(SelectFilter $filter, array $state): array
    {
        $ids = array_filter((array) ($state['values'] ?? $state['value'] ?? []));
        if (! $ids) {
            return [];
        }
        $names = Branch::whereKey($ids)->get()->map->pickLabel()->implode(', ');

        return $names === '' ? [] : [$filter->getLabel() . ': ' . $names];
    }

    /** The same full-name, searchable behaviour for a form Select holding a branch id. */
    public static function branchSelect(Select $select, ?\Closure $scope = null): Select
    {
        $options = function (?string $search, int $limit) use ($scope) {
            $q = Branch::query()->orderBy('name')->orderBy('city');
            if ($scope) {
                $scope($q);
            }
            if (filled($search)) {
                $like = '%' . trim($search) . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('city', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('incharge', 'like', $like));
            }

            return $q->limit($limit)->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->pickLabel()])->all();
        };

        return $select
            ->options(fn () => $options(null, 50))
            ->getSearchResultsUsing(fn (string $search) => $options($search, 50))
            ->getOptionLabelUsing(fn ($value) => Branch::find($value)?->pickLabel())
            ->searchable();
    }

    public static function dateRange(string $column, string $label = 'Date'): Filter
    {
        return Filter::make($column . '_range')
            ->form([
                DatePicker::make('from')->label($label . ' from')->native(false),
                DatePicker::make('to')->label($label . ' to')->native(false),
            ])
            ->columns(2)
            ->query(fn ($query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate($q->qualifyColumn($column), '>=', $v))
                ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate($q->qualifyColumn($column), '<=', $v)))
            ->indicateUsing(fn (array $data) => array_filter([
                ($data['from'] ?? null) ? $label . ' from ' . \Illuminate\Support\Carbon::parse($data['from'])->format('d M Y') : null,
                ($data['to'] ?? null) ? $label . ' to ' . \Illuminate\Support\Carbon::parse($data['to'])->format('d M Y') : null,
            ]));
    }
}
