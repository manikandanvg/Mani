<?php

namespace App\Filament\Resources\DriveFolderResource\Pages;

use App\Filament\Resources\DriveFolderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDriveFolders extends ListRecords
{
    protected static string $resource = DriveFolderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
