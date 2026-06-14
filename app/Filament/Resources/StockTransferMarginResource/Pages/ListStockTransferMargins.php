<?php

namespace App\Filament\Resources\StockTransferMarginResource\Pages;

use App\Filament\Resources\StockTransferMarginResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockTransferMargins extends ListRecords
{
    protected static string $resource = StockTransferMarginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
