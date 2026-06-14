<?php

namespace App\Filament\Resources\EpinResource\Pages;

use App\Filament\Resources\EpinResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpin extends EditRecord
{
    protected static string $resource = EpinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
