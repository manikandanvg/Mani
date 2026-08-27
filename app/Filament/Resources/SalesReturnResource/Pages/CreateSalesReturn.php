<?php

namespace App\Filament\Resources\SalesReturnResource\Pages;

use App\Filament\Resources\SalesReturnResource;
use App\Services\SalesReturnService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateSalesReturn extends CreateRecord
{
    protected static string $resource = SalesReturnResource::class;

    /**
     * Funnel creation through the service: live-rate valuation, return number, coin
     * product resolution. Service 422s surface as a notification on the form.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $user = auth()->user();
            $branchId = $user?->isDistributor() ? $user->branch_id : ($data['branch_id'] ?? $user?->branch_id);
            abort_unless($branchId, 422, 'Pick the branch this return belongs to.');

            return app(SalesReturnService::class)->create([
                'branch_id' => $branchId,
                'member_id' => $data['member_id'] ?? null,
                'catalog_product_id' => $data['catalog_product_id'] ?? null,
                'quantity' => $data['quantity'] ?? 0,
                'collect_on' => $data['collect_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        } catch (HttpException $e) {
            Notification::make()->danger()
                ->title('Cannot record sales return')
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
