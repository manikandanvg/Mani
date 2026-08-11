<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\Push\Notifier;
use Illuminate\Console\Command;

/**
 * "Meeting starting soon" pushes (board 2026-08-11): published meetings starting
 * within the next hour get one broadcast, stamped via reminder_sent_at so repeat
 * runs never re-send. Scheduled every 15 minutes (also swept by cron:run as a
 * fallback for hosts without a per-minute scheduler).
 */
class MeetingReminders extends Command
{
    protected $signature = 'notify:meeting-reminders';

    protected $description = 'Push a "starting soon" reminder for published meetings beginning within the hour';

    public function handle(): int
    {
        $due = Meeting::published()
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at', [now(), now()->addHour()])
            ->get();

        foreach ($due as $meeting) {
            $n = Notifier::broadcast(
                'news',
                'Live meeting soon: ' . $meeting->title,
                $meeting->scheduled_at->format('h:i A') . ' today on ' . ($meeting->platform ?: 'the meeting link')
                    . ($meeting->host_name ? ' — hosted by ' . $meeting->host_name : '') . '. Tap to join.',
                route: '/meetings/' . $meeting->id,
                data: ['join_url' => (string) $meeting->join_url],
            );
            $meeting->forceFill(['reminder_sent_at' => now()])->save();
            $this->line("  reminded {$n} member(s): {$meeting->title}");
        }

        if ($due->isEmpty()) {
            $this->line('  no meetings starting within the hour.');
        }

        return self::SUCCESS;
    }
}
