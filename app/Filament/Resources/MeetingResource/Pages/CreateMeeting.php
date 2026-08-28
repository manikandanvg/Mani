<?php

namespace App\Filament\Resources\MeetingResource\Pages;

use App\Filament\Resources\MeetingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMeeting extends CreateRecord
{
    protected static string $resource = MeetingResource::class;

    /** Meeting-scheduled broadcast (board 2026-08-11); the 1-hour reminder is notify:meeting-reminders. */
    protected function afterCreate(): void
    {
        $meeting = $this->getRecord();

        // Zoom auto-create (board phase-1, 2026-08-21): a Zoom meeting saved without
        // a link is created AT Zoom via the S2S API; id/link/passcode fill themselves.
        $zoomApi = app(\App\Services\Zoom\ZoomApiService::class);
        if ($meeting->platform === 'zoom' && blank($meeting->join_url) && $zoomApi->configured()) {
            if ($z = $zoomApi->createMeeting($meeting)) {
                $meeting->update($z);
                $meeting->refresh();
                \Filament\Notifications\Notification::make()
                    ->title('Created at Zoom')
                    ->body("Meeting ID {$z['meeting_id']} — join link and passcode filled in.")
                    ->success()->send();
            } else {
                \Filament\Notifications\Notification::make()
                    ->title('Zoom auto-create failed')
                    ->body('The meeting was saved here, but Zoom did not accept it — check the Zoom credentials or add the join link by hand.')
                    ->warning()->persistent()->send();
            }
        }

        if (! $meeting->is_published || ! $meeting->scheduled_at) {
            return;
        }

        // Board phase 2 (2026-08-28): the announcement goes only to the selected
        // audience — everyone, all distributors, or the exact ranks picked (multi).
        $audience = \App\Models\Member::query();
        if ($depths = $meeting->audienceDepths()) {
            $audience->whereHas('rank', fn ($q) => $q->whereIn('depth', $depths));
        }

        $n = \App\Services\Push\Notifier::toQuery(
            $audience,
            'news',
            'Live meeting: ' . $meeting->title,
            $meeting->scheduled_at->format('d M Y, h:i A') . ' on ' . ($meeting->platform ?: 'the meeting link')
                . ($meeting->host_name ? ' — hosted by ' . $meeting->host_name : '') . '. Save the date!',
            route: '/meetings/' . $meeting->id,
            data: ['join_url' => (string) $meeting->join_url],
        );

        \Filament\Notifications\Notification::make()
            ->title("Meeting announced — {$n} member(s) notified")
            ->success()->send();
    }
}
