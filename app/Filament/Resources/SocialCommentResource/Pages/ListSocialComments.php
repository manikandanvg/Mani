<?php

namespace App\Filament\Resources\SocialCommentResource\Pages;

use App\Filament\Resources\SocialCommentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSocialComments extends ListRecords
{
    protected static string $resource = SocialCommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
