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
        if (! $meeting->is_published || ! $meeting->scheduled_at) {
            return;
        }

        $n = \App\Services\Push\Notifier::broadcast(
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
