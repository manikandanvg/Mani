<?php

namespace App\Filament\Resources\BranchSourceChangeRequestResource\Pages;

use App\Filament\Resources\BranchSourceChangeRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBranchSourceChangeRequests extends ListRecords
{
    protected static string $resource = BranchSourceChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New request'),
        ];
    }
}
