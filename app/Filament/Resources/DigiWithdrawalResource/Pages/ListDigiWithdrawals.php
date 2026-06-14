<?php

namespace App\Filament\Resources\DigiWithdrawalResource\Pages;

use App\Filament\Resources\DigiWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDigiWithdrawals extends ListRecords
{
    protected static string $resource = DigiWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
