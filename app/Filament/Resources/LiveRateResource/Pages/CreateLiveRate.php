<?php

namespace App\Filament\Resources\LiveRateResource\Pages;

use App\Filament\Resources\LiveRateResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLiveRate extends CreateRecord
{
    protected static string $resource = LiveRateResource::class;

    /**
     * Price-change notification (board 2026-08-11): every app-registered member gets
     * a push + inbox entry the moment HQ publishes a new board rate.
     */
    protected function afterCreate(): void
    {
        $rate = $this->getRecord();

        $n = \App\Services\Push\Notifier::broadcast(
            'rates',
            'Gold & silver rates updated',
            'Gold ₹' . number_format((float) $rate->gold, 2) . '/g · Silver ₹'
                . number_format((float) $rate->silver, 2) . '/g — effective now at Lord Jeweller.',
            route: '/rates',
        );

        \Filament\Notifications\Notification::make()
            ->title("Rate published — {$n} member(s) notified")
            ->success()->send();
    }
}
