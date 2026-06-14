<?php

namespace App\Filament\Resources\CatalogProductResource\Pages;

use App\Filament\Resources\CatalogProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCatalogProduct extends EditRecord
{
    protected static string $resource = CatalogProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
