<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Services\Zoom\ZoomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull-based safety net for "verified minutes" (2026-08-25). The participant
 * webhooks are the live path; this asks Zoom for the participant report of
 * every finished meeting and writes the same source='zoom' rows the webhooks
 * would have — so a mis-configured event subscription, a missed delivery or a
 * server outage never leaves a member's participation at zero. Idempotent:
 * an existing row for the same participant + join time is completed, not
 * duplicated. Scheduled every 30 minutes; run by hand with --meeting=<id>.
 * Needs the Server-to-Server scope meeting:read:list_past_participants:admin.
 */
class ZoomSyncAttendance extends Command
{
    protected $signature = 'zoom:sync-attendance
        {--meeting= : only this meeting — our id or the Zoom meeting number — even if it has not ended yet}
        {--days=3 : look back this many days for ended Zoom meetings}';

    protected $description = 'Reconcile meeting attendance with Zoom\'s participant report (backs up the webhooks)';

    public function handle(ZoomApiService $api): int
    {
        if (! $api->configured()) {
            $this->components->error('Zoom Server-to-Server app not configured (ZOOM_ACCOUNT_ID / ZOOM_CLIENT_ID / ZOOM_CLIENT_SECRET)');

            return self::FAILURE;
        }

        if (($want = trim((string) $this->option('meeting'))) !== '') {
            // Our id (short) or the Zoom meeting number (9–11 digits, as shown in the admin form).
            $meetings = Meeting::query()
                ->when(strlen($want) >= 9, fn ($q) => $q->where('meeting_id', $want), fn ($q) => $q->whereKey((int) $want))
                ->get();
            if ($meetings->isEmpty()) {
                $this->components->error("No meeting with id or Zoom number {$want} — check Admin → Meetings");

                return self::FAILURE;
            }
        }

        $meetings ??= Meeting::query()
                ->where('platform', 'zoom')
                ->whereNotNull('meeting_id')
                ->where('scheduled_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
                ->get()
                ->filter(fn (Meeting $m) => $m->status() === 'ended');

        $failed = 0;
        foreach ($meetings as $meeting) {
            $number = preg_replace('/\D/', '', (string) $meeting->meeting_id);
            if ($number === '') {
                continue;
            }

            $participants = $api->pastParticipants($number);
            if ($participants === null) {
                $this->components->warn("#{$meeting->id} {$meeting->title}: Zoom refused the participant report — scope meeting:read:list_past_participants:admin missing, or the meeting never ran (see laravel.log)");
                $failed++;

                continue;
            }

            [$created, $updated] = $this->apply($meeting, $participants);
            $this->components->twoColumnDetail("#{$meeting->id} {$meeting->title}", count($participants) . " participants — {$created} new, {$updated} completed");
        }

        if ($meetings->isEmpty()) {
            $this->components->info('No ended Zoom meetings in the window.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{int,int} [created, updated] */
    protected function apply(Meeting $meeting, array $participants): array
    {
        $created = $updated = 0;
        foreach ($participants as $p) {
            $name = trim((string) ($p['name'] ?? $p['user_name'] ?? ''));
            $email = (string) ($p['user_email'] ?? $p['email'] ?? '');
            $pid = (string) ($p['user_id'] ?? '');
            $joined = $this->time($p['join_time'] ?? null);
            if (! $joined) {
                continue;
            }
            $left = $this->time($p['leave_time'] ?? null);
            $seconds = (int) ($p['duration'] ?? 0);
            if (! $left && $seconds > 0) {
                $left = $joined->copy()->addSeconds($seconds);
            }
            $minutes = $left ? max(1, (int) round($joined->diffInSeconds($left) / 60)) : null;

            // Same participant, same join minute → the webhook (or an earlier run)
            // already has it; just complete a row that never got its leave event.
            $row = MeetingAttendance::query()
                ->where('meeting_id', $meeting->id)
                ->where('source', 'zoom')
                ->whereBetween('joined_at', [$joined->copy()->subMinute(), $joined->copy()->addMinute()])
                ->where(fn ($q) => $pid !== ''
                    ? $q->where('zoom_participant_id', $pid)->orWhere('participant_name', $name)
                    : $q->where('participant_name', $name))
                ->first();

            if ($row) {
                $changes = [];
                if (! $row->left_at && $left) {
                    $changes += ['left_at' => $left, 'duration_min' => $minutes];
                }
                if (! $row->member_id && ($mid = MeetingAttendance::matchMember($name, $email))) {
                    $changes['member_id'] = $mid;
                }
                if ($changes) {
                    $row->update($changes);
                    $updated++;
                }

                continue;
            }

            MeetingAttendance::create([
                'meeting_id' => $meeting->id,
                'member_id' => MeetingAttendance::matchMember($name, $email),
                'participant_name' => $name ?: null,
                'zoom_participant_id' => $pid ?: null,
                'source' => 'zoom',
                'joined_at' => $joined,
                'left_at' => $left,
                'duration_min' => $minutes,
            ]);
            $created++;
        }

        return [$created, $updated];
    }

    protected function time(?string $iso): ?Carbon
    {
        try {
            return $iso ? Carbon::parse($iso)->timezone(config('app.timezone')) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
