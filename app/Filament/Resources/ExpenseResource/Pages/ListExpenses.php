<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getSubheading(): ?string
    {
        return 'Every expense Head Office pays outside the system — TBP awards (car, tour), office rent, salaries, bills. Approved expenses go into the auditor\'s Balance Sheet and Profit & Loss.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Submit expense'),
        ];
    }
}
