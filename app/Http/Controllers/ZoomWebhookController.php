<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Zoom event webhooks (board phase-1, 2026-08-21) — attendance capture.
 * Subscribed events: meeting.participant_joined / meeting.participant_left.
 * Authenticity: Zoom's HMAC header signature (services.zoom.webhook_secret);
 * the endpoint.url_validation challenge is answered the way Zoom requires.
 */
class ZoomWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = (string) config('services.zoom.webhook_secret');
        $event = (string) $request->input('event');

        // Zoom validates the endpoint URL with a signed challenge on save.
        if ($event === 'endpoint.url_validation') {
            $plain = (string) $request->input('payload.plainToken');

            return response()->json([
                'plainToken' => $plain,
                'encryptedToken' => hash_hmac('sha256', $plain, $secret),
            ]);
        }

        $ts = (string) $request->header('x-zm-request-timestamp');
        $expected = 'v0=' . hash_hmac('sha256', "v0:{$ts}:" . $request->getContent(), $secret);
        abort_unless($secret !== '' && hash_equals($expected, (string) $request->header('x-zm-signature')), 401);

        $object = (array) $request->input('payload.object', []);
        $meeting = Meeting::where('meeting_id', (string) ($object['id'] ?? ''))->first();
        if (! $meeting) {
            return response()->json(['ok' => true]);   // not one of ours
        }

        $p = (array) ($object['participant'] ?? []);
        $pid = (string) ($p['user_id'] ?? $p['participant_user_id'] ?? $p['id'] ?? '');
        $name = trim((string) ($p['user_name'] ?? ''));

        if ($event === 'meeting.participant_joined') {
            MeetingAttendance::create([
                'meeting_id' => $meeting->id,
                // app-minted join links carry the member's own name — an exact
                // match ties the Zoom row back to the member
                'member_id' => $name !== '' ? Member::where('name', $name)->value('id') : null,
                'participant_name' => $name ?: null,
                'zoom_participant_id' => $pid ?: null,
                'source' => 'zoom',
                'joined_at' => $this->time($p['join_time'] ?? null),
            ]);
        } elseif ($event === 'meeting.participant_left') {
            $row = MeetingAttendance::where('meeting_id', $meeting->id)
                ->where('source', 'zoom')
                ->when($pid !== '', fn ($q) => $q->where('zoom_participant_id', $pid),
                    fn ($q) => $q->where('participant_name', $name))
                ->whereNull('left_at')
                ->latest('joined_at')
                ->first();
            if ($row) {
                $left = $this->time($p['leave_time'] ?? null);
                $row->update([
                    'left_at' => $left,
                    'duration_min' => max(1, (int) ceil($row->joined_at->diffInSeconds($left) / 60)),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    protected function time(?string $iso): Carbon
    {
        try {
            return $iso ? Carbon::parse($iso)->timezone(config('app.timezone')) : now();
        } catch (\Throwable) {
            return now();
        }
    }
}
