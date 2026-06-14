<?php

namespace App\Filament\Resources\DigiQueueResource\Pages;

use App\Filament\Resources\DigiQueueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDigiQueues extends ListRecords
{
    protected static string $resource = DigiQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
