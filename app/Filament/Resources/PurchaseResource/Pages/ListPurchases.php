<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected static ?string $title = 'Purchase Manager';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New Purchase')];
    }
}
