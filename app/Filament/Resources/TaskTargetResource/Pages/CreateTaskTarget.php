<?php

namespace App\Filament\Resources\TaskTargetResource\Pages;

use App\Filament\Resources\TaskTargetResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskTarget extends CreateRecord
{
    protected static string $resource = TaskTargetResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
