<?php

namespace App\Filament\Resources\ChargeBracketResource\Pages;

use App\Filament\Resources\ChargeBracketResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChargeBrackets extends ListRecords
{
    protected static string $resource = ChargeBracketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
