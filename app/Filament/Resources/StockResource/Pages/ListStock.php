<?php

namespace App\Filament\Resources\StockResource\Pages;

use App\Filament\Resources\StockResource;
use Filament\Resources\Pages\ListRecords;

class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    protected static ?string $title = 'Stock';
}
