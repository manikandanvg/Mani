<?php

namespace App\Filament\Resources\SalesReturnResource\Pages;

use App\Filament\Resources\SalesReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesReturns extends ListRecords
{
    protected static string $resource = SalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Record a sales return'),
        ];
    }
}
