<?php

namespace App\Filament\Resources\CommissionLedgerResource\Pages;

use App\Filament\Resources\CommissionLedgerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommissionLedger extends EditRecord
{
    protected static string $resource = CommissionLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
