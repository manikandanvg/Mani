<?php

namespace App\Filament\Resources\DriveFileResource\Pages;

use App\Filament\Resources\DriveFileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriveFile extends EditRecord
{
    protected static string $resource = DriveFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
