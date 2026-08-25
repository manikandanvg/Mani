<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Zoom event webhooks (board phase-1, 2026-08-21) — attendance capture.
 * Subscribed events: meeting.participant_joined / meeting.participant_left /
 * meeting.ended (closes rows whose leave event never arrived).
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
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('x-zm-signature'))) {
            // Almost always the Secret Token of the OTHER marketplace app (we have two):
            // ZOOM_WEBHOOK_SECRET must be the token of the app whose Event Subscription
            // points here. Logged so "verified minutes never appear" is diagnosable (2026-08-25).
            \Illuminate\Support\Facades\Log::warning('Zoom webhook rejected: signature mismatch', [
                'event' => $event,
                'meeting' => $request->input('payload.object.id'),
                'has_signature' => $request->hasHeader('x-zm-signature'),
            ]);
            abort(401);
        }

        $object = (array) $request->input('payload.object', []);
        $meeting = Meeting::where('meeting_id', (string) ($object['id'] ?? ''))->first();
        if (! $meeting) {
            return response()->json(['ok' => true]);   // not one of ours
        }

        // The subscription may carry every meeting event type; only three matter here.
        // One line per handled event makes "did the leave ever arrive?" answerable
        // from laravel.log on live (2026-08-25).
        if (in_array($event, ['meeting.participant_joined', 'meeting.participant_left', 'meeting.ended'], true)) {
            \Illuminate\Support\Facades\Log::info("Zoom webhook {$event}", [
                'meeting' => $meeting->id,
                'zoom' => $meeting->meeting_id,
                'participant' => $object['participant']['user_id'] ?? null,
                'name' => $object['participant']['user_name'] ?? null,
            ]);
        }

        // Host ended the meeting: every still-open Zoom row is closed at end_time
        // (Zoom sends no participant_left for people present at the end).
        if ($event === 'meeting.ended') {
            $ended = $this->time($object['end_time'] ?? null);
            MeetingAttendance::where('meeting_id', $meeting->id)
                ->where('source', 'zoom')
                ->whereNull('left_at')
                ->each(fn (MeetingAttendance $row) => $row->update([
                    'left_at' => $ended,
                    'duration_min' => max(1, (int) round($row->joined_at->diffInSeconds($ended) / 60)),
                ]));

            return response()->json(['ok' => true]);
        }

        $p = (array) ($object['participant'] ?? []);
        $pid = (string) ($p['user_id'] ?? $p['participant_user_id'] ?? $p['id'] ?? '');
        $name = trim((string) ($p['user_name'] ?? ''));
        $email = strtolower(trim((string) ($p['email'] ?? '')));

        if ($event === 'meeting.participant_joined') {
            MeetingAttendance::create([
                'meeting_id' => $meeting->id,
                'member_id' => $this->matchMember($name, $email),
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
                    'duration_min' => max(1, (int) round($row->joined_at->diffInSeconds($left) / 60)),
                ]);
            } else {
                // Either the join never reached us, or zoom:sync-attendance already
                // closed the row from Zoom's report before this event was delivered.
                \Illuminate\Support\Facades\Log::info('Zoom participant_left with no open row', [
                    'meeting' => $meeting->id, 'participant' => $pid, 'name' => $name,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Tie a Zoom participant back to a member. App-minted join links put the
     * member code after the name ("Priya · LJW01"), so the code is the reliable
     * key; a Zoom-account e-mail and an exact name are the fallbacks.
     */
    protected function matchMember(string $name, string $email): ?int
    {
        return MeetingAttendance::matchMember($name, $email);
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
