<?php

namespace App\Filament\Resources\CommissionLedgerResource\Pages;

use App\Filament\Resources\CommissionLedgerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommissionLedgers extends ListRecords
{
    protected static string $resource = CommissionLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
