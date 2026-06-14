<?php

namespace App\Filament\Resources\LiveRateResource\Pages;

use App\Filament\Resources\LiveRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLiveRate extends EditRecord
{
    protected static string $resource = LiveRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
