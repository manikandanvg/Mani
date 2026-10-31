<?php

namespace App\Filament\Resources\ActivityLogResource\Pages;

use App\Filament\Resources\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected static ?string $title = 'Activity Log';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
