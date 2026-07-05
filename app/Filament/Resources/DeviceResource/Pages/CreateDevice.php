<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    /** Surface the auto-generated pairing code immediately after creation. */
    protected function afterCreate(): void
    {
        $device = $this->record;
        Notification::make()
            ->title("Pairing code: {$device->pairing_code}")
            ->body("Serial {$device->serial_no} — enter this on the device at first boot.")
            ->success()->persistent()->send();
    }
}
