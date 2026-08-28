<?php

namespace App\Filament\Resources\MemoResource\Pages;

use App\Filament\Resources\MemoResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateMemo extends CreateRecord
{
    protected static string $resource = MemoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** Saving IS sending: push + inbox to every app-registered distributor. */
    protected function afterCreate(): void
    {
        $n = MemoResource::broadcast($this->getRecord());

        Notification::make()->success()
            ->title("Memo sent — {$n} member(s) notified")
            ->body($n === 0 ? 'No distributor has registered an app device yet.' : null)
            ->send();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;   // the "Memo sent" toast above replaces the generic "Created"
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
