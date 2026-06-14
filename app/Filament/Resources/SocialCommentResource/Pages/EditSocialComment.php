<?php

namespace App\Filament\Resources\SocialCommentResource\Pages;

use App\Filament\Resources\SocialCommentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSocialComment extends EditRecord
{
    protected static string $resource = SocialCommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
