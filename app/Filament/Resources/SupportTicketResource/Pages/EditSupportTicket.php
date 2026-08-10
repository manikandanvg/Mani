<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportTicket extends EditRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('close')
                ->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $this->getRecord()->status === 'open')
                ->requiresConfirmation()
                ->action(function () {
                    $this->getRecord()->update([
                        'status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(),
                    ]);
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('reopen')
                ->icon('heroicon-o-arrow-path')->color('warning')
                ->visible(fn () => $this->getRecord()->status === 'closed')
                ->requiresConfirmation()
                ->action(function () {
                    $this->getRecord()->update([
                        'status' => 'open', 'closed_by' => null, 'closed_at' => null,
                    ]);
                    $this->refreshFormData(['status']);
                }),
            Actions\DeleteAction::make()->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
        ];
    }
}
