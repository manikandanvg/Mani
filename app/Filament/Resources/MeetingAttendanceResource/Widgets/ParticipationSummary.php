<?php

namespace App\Filament\Resources\MeetingAttendanceResource\Widgets;

use App\Filament\Resources\MeetingAttendanceResource\Pages\ListMeetingAttendances;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers for whatever the table is currently filtered to (date
 * interval / meeting / source) — "how many joined in this period" at a glance.
 */
class ParticipationSummary extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListMeetingAttendances::class;
    }

    protected function getStats(): array
    {
        $q = $this->getPageTableQuery();

        $rows = (clone $q)->count();
        $people = (int) (clone $q)->reorder()
            ->selectRaw("count(distinct member_id) + count(distinct case when member_id is null then coalesce(zoom_participant_id, participant_name) end) as n")
            ->value('n');
        $meetings = (int) (clone $q)->reorder()->distinct()->count('meeting_id');
        $minutes = (int) (clone $q)->reorder()->sum('duration_min');

        return [
            Stat::make('People joined', number_format($people))
                ->description($rows === $people ? 'distinct participants' : number_format($rows) . ' join rows (distinct people shown)'),
            Stat::make('Meetings', number_format($meetings))->description('in the current filter'),
            Stat::make('Minutes attended', number_format($minutes))->description('Zoom-verified durations'),
        ];
    }
}
