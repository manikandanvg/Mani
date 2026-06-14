<?php

namespace App\Filament\Resources\DigiOrderResource\Pages;

use App\Filament\Resources\DigiOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDigiOrders extends ListRecords
{
    protected static string $resource = DigiOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
