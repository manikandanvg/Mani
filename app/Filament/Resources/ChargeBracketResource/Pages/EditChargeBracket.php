<?php

namespace App\Filament\Resources\ChargeBracketResource\Pages;

use App\Filament\Resources\ChargeBracketResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChargeBracket extends EditRecord
{
    protected static string $resource = ChargeBracketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
