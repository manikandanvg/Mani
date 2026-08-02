<?php

namespace App\Filament\Resources\StockReturnResource\Pages;

use App\Filament\Resources\StockReturnResource;
use App\Services\StockReturnService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateStockReturn extends CreateRecord
{
    protected static string $resource = StockReturnResource::class;

    /**
     * Funnel creation through the service: live-rate pricing, stock checks, voucher no.
     * Service 422s (e.g. "branch doesn't hold that much") surface as a notification on
     * the form, not an error page.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            abort_unless(auth()->user()?->branch_id, 422, 'Your login has no branch — only a branch account can return stock.');

            return app(StockReturnService::class)->submit([
                'branch_id' => auth()->user()->branch_id,
                'created_by' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'lines' => $data['lines'] ?? [],
            ]);
        } catch (HttpException $e) {
            Notification::make()->danger()
                ->title('Cannot submit stock return')
                ->body($e->getMessage())
                ->persistent()
                ->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
