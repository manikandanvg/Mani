<?php

namespace App\Filament\Resources\RankChangeResource\Pages;

use App\Filament\Resources\RankChangeResource;
use Filament\Resources\Pages\ListRecords;

class ListRankChanges extends ListRecords
{
    protected static string $resource = RankChangeResource::class;

    protected static ?string $title = 'Rank Changes';

    public function getSubheading(): ?string
    {
        return 'Every TBP-stage shift with its date: billing-time promotions, cut-off recomputes, invoice cancellations and the calibration from bond history.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
