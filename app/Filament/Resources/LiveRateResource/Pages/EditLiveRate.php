<?php

namespace App\Filament\Resources\LiveRateResource\Pages;

use App\Filament\Resources\LiveRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLiveRate extends EditRecord
{
    protected static string $resource = LiveRateResource::class;

    /** Gold / silver before this save — to word the "up / down from" message. */
    protected ?array $previous = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $r = $this->getRecord();
        $this->previous = ['gold' => (float) $r->getOriginal('gold'), 'silver' => (float) $r->getOriginal('silver')];
    }

    /** Board 2026-10-31 (20): a CHANGED rate (edit, not just a re-save) is announced to every member. */
    protected function afterSave(): void
    {
        $rate = $this->getRecord()->fresh();
        $changed = $this->previous === null
            || abs((float) $rate->gold - $this->previous['gold']) >= 0.005
            || abs((float) $rate->silver - $this->previous['silver']) >= 0.005;
        if (! $changed) {
            return;
        }

        $n = LiveRateResource::announce($rate, $this->previous);

        \Filament\Notifications\Notification::make()
            ->title("Rate changed — {$n} member(s) notified")
            ->success()->send();
    }
}
