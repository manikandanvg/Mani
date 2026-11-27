<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Submit expense'),
        ];
    }

    /** Board 2026-11-27: the auditor files two reports — the expenses and the capital assets. */
    public function getTabs(): array
    {
        $assets = array_keys(Expense::ASSET_CATEGORIES);

        return [
            'all' => Tab::make('All'),
            // NB: the parameter MUST be named $query — Filament injects it by name.
            'expenses' => Tab::make('Expenses')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('category', $assets)),
            'assets' => Tab::make('Capital assets')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('category', $assets)),
            'waiting' => Tab::make('Waiting for approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Expense::SUBMITTED)),
        ];
    }
}
