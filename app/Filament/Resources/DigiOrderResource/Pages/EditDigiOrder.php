<?php

namespace App\Filament\Resources\DigiOrderResource\Pages;

use App\Filament\Resources\DigiOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDigiOrder extends EditRecord
{
    protected static string $resource = DigiOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
