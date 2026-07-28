<?php

namespace App\Filament\Resources\StockReturnResource\Pages;

use App\Filament\Resources\StockReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockReturns extends ListRecords
{
    protected static string $resource = StockReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Return stock to HQ'),
        ];
    }
}
