<?php

namespace App\Filament\Resources\TaskTargetResource\Pages;

use App\Filament\Resources\TaskTargetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskTargets extends ListRecords
{
    protected static string $resource = TaskTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
