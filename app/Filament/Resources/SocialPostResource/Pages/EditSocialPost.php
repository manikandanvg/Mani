<?php

namespace App\Filament\Resources\SocialPostResource\Pages;

use App\Filament\Resources\SocialPostResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSocialPost extends EditRecord
{
    protected static string $resource = SocialPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
