<?php

namespace App\Filament\Resources\RankChangeResource\Pages;

use App\Filament\Resources\RankChangeResource;
use Filament\Resources\Pages\ListRecords;

class ListRankChanges extends ListRecords
{
    protected static string $resource = RankChangeResource::class;

    protected static ?string $title = 'Rank Changes';


    protected function getHeaderActions(): array
    {
        return [];
    }
}
