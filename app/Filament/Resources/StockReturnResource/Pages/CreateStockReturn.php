<?php

namespace App\Filament\Resources\StockReturnResource\Pages;

use App\Filament\Resources\StockReturnResource;
use App\Services\StockReturnService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockReturn extends CreateRecord
{
    protected static string $resource = StockReturnResource::class;

    /** Funnel creation through the service: live-rate pricing, stock checks, voucher no. */
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(auth()->user()?->branch_id, 422, 'Your login has no branch — only a branch account can return stock.');

        return app(StockReturnService::class)->submit([
            'branch_id' => auth()->user()->branch_id,
            'created_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
            'lines' => $data['lines'] ?? [],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
