<?php

namespace App\Filament\Resources\EpinResource\Pages;

use App\Filament\Resources\EpinResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEpins extends ListRecords
{
    protected static string $resource = EpinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
