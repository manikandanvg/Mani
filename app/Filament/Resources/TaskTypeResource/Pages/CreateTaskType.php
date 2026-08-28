<?php

namespace App\Filament\Resources\TaskTypeResource\Pages;

use App\Filament\Resources\TaskTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskType extends CreateRecord
{
    protected static string $resource = TaskTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
