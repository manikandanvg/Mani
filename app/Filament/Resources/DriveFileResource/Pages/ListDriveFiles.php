<?php

namespace App\Filament\Resources\DriveFileResource\Pages;

use App\Filament\Resources\DriveFileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDriveFiles extends ListRecords
{
    protected static string $resource = DriveFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
