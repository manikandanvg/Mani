<?php

namespace App\Filament\Resources\CatalogProductResource\Pages;

use App\Filament\Resources\CatalogProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCatalogProduct extends CreateRecord
{
    protected static string $resource = CatalogProductResource::class;
}
