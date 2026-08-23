<?php

namespace App\Filament\Resources\MeetingAttendanceResource\Pages;

use App\Filament\Resources\MeetingAttendanceResource;
use App\Filament\Resources\MeetingAttendanceResource\Widgets\ParticipationSummary;
use Filament\Resources\Pages\ListRecords;

class ListMeetingAttendances extends ListRecords
{
    protected static string $resource = MeetingAttendanceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ParticipationSummary::class];
    }
}
