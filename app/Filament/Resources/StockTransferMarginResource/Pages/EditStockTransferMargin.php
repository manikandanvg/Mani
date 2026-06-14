<?php

namespace App\Filament\Resources\StockTransferMarginResource\Pages;

use App\Filament\Resources\StockTransferMarginResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockTransferMargin extends EditRecord
{
    protected static string $resource = StockTransferMarginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
