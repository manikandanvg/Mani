<?php

namespace App\Filament\Resources\FirmwareResource\Pages;

use App\Filament\Resources\FirmwareResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateFirmware extends CreateRecord
{
    protected static string $resource = FirmwareResource::class;

    /** Stamp SHA-256 + size from the uploaded binary — devices verify against these. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $disk = Storage::disk('local');
        $data['sha256'] = hash('sha256', $disk->get($data['path']));
        $data['size_bytes'] = $disk->size($data['path']);
        $data['is_active'] = false;   // explicit Activate action goes live

        return $data;
    }
}
