<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Services\TradePurchaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static ?string $title = 'New Purchase';

    /** Delegate to the service so lines + stock + movements are written atomically. */
    protected function handleRecordCreation(array $data): Model
    {
        $data['created_by'] = auth()->id();

        return app(TradePurchaseService::class)->record($data);
    }
}
