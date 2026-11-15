<?php

namespace App\Filament\Resources\BondResource\Pages;

use App\Filament\Resources\BondResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBond extends EditRecord
{
    protected static string $resource = BondResource::class;

    protected function getHeaderActions(): array
    {
        return [];   // board 2026-11-15: a contract is never deleted from the panel
    }
}
