<?php

namespace App\Filament\Resources\LiveRateResource\Pages;

use App\Filament\Resources\LiveRateResource;
use App\Models\LiveRate;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLiveRate extends CreateRecord
{
    protected static string $resource = LiveRateResource::class;

    /**
     * Price-change notification (board 2026-08-11, wording per board 2026-10-31 item 20):
     * every app-registered member gets a push + inbox entry the moment HQ publishes a new
     * board rate — with the move against the previous rate for the same country.
     */
    protected function afterCreate(): void
    {
        $rate = $this->getRecord();
        $previous = LiveRate::where('country', $rate->country)
            ->where('id', '!=', $rate->id)
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->first();

        $n = LiveRateResource::announce($rate, $previous ? ['gold' => (float) $previous->gold, 'silver' => (float) $previous->silver] : null);

        \Filament\Notifications\Notification::make()
            ->title("Rate published — {$n} member(s) notified")
            ->success()->send();
    }
}
