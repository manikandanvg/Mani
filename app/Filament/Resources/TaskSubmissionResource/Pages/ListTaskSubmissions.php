<?php

namespace App\Filament\Resources\TaskSubmissionResource\Pages;

use App\Filament\Resources\TaskSubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskSubmissions extends ListRecords
{
    protected static string $resource = TaskSubmissionResource::class;
}
