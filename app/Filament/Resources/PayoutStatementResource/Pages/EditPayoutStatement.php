<?php

namespace App\Filament\Resources\PayoutStatementResource\Pages;

use App\Filament\Resources\PayoutStatementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayoutStatement extends EditRecord
{
    protected static string $resource = PayoutStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
