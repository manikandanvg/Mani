<?php

namespace App\Filament\Resources\TaskTargetResource\Pages;

use App\Filament\Resources\TaskTargetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaskTarget extends EditRecord
{
    protected static string $resource = TaskTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
