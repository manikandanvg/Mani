<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live & Learn (Phase 6a) — the app's list of scheduled meetings. Members see
 * 'members' + 'public'; retail customers see 'public' only. The app opens
 * `join_url` (Zoom etc.) externally; the SDK embed is a later concern.
 */
class MeetingController extends Controller
{
    /** GET /meetings — upcoming/live first, then recent past (last 7 days). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $levels = $user instanceof Member ? ['members', 'public'] : ['public'];
        // Zoom display name carries the member code so the participant webhook
        // can tie the Zoom row back to the member (see ZoomWebhookController).
        $displayName = (string) ($user->name ?? 'LORDICL Member');
        if ($user instanceof Member && filled($user->member_code)) {
            $displayName .= ' · ' . $user->member_code;
        }
        // Rank-targeted meetings (item 7): only visible from the required TBP stage up.
        $myDepth = $user instanceof Member ? (int) ($user->rank?->depth ?? 0) : 0;

        $meetings = Meeting::published()
            ->whereIn('visibility', $levels)
            ->where(fn ($q) => $q->whereNull('min_rank_depth')->orWhere('min_rank_depth', '<=', $myDepth))
            ->where('scheduled_at', '>=', now()->subDays(7))
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Meeting $m) => $this->present($m, $displayName, $user instanceof Member ? $user->id : null));

        // Group for the app: live + upcoming together (soonest first), past separate.
        return response()->json([
            'live' => $meetings->where('status', 'live')->values(),
            'upcoming' => $meetings->where('status', 'upcoming')->values(),
            'past' => $meetings->where('status', 'ended')->sortByDesc('scheduled_at')->values(),
        ]);
    }

    /**
     * POST /meetings/{meeting}/joined — the app logs attendance when the member
     * taps Join on the external Zoom link (the signed in-app page logs itself).
     */
    public function joined(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403);
        abort_unless($meeting->is_published, 404);

        \App\Models\MeetingAttendance::firstOrCreate(
            ['meeting_id' => $meeting->id, 'member_id' => $user->id, 'source' => 'app'],
            ['participant_name' => $user->name, 'joined_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /member/meeting-attendance — the member's attended meetings, shown in
     * the app's Training Library (board phase-1, 2026-08-21).
     */
    public function myAttendance(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403);

        $rows = \App\Models\MeetingAttendance::with('meeting')
            ->where('member_id', $user->id)
            ->orderByDesc('joined_at')
            ->limit(100)
            ->get();

        return response()->json([
            'attendance' => $rows->map(fn ($a) => [
                'meeting_id' => $a->meeting_id,
                'title' => $a->meeting?->title,
                'scheduled_at' => $a->meeting?->scheduled_at?->toIso8601String(),
                'joined_at' => $a->joined_at?->toIso8601String(),
                'left_at' => $a->left_at?->toIso8601String(),
                'duration_min' => $a->duration_min,
                'source' => $a->source,
            ])->values(),
        ]);
    }

    protected function present(Meeting $m, string $displayName = 'LORDICL Member', ?int $memberId = null): array
    {
        // In-app join (2026-08-12): a signed Web-SDK page URL, minted per user so
        // their name shows in the meeting. Null when the SDK isn't configured or
        // the meeting has no numeric Zoom id — the app falls back to join_url.
        // The member id rides in the signed query so opening the page logs attendance.
        $zoom = app(\App\Services\Zoom\ZoomSdkService::class);
        $appJoinUrl = null;
        if ($zoom->configured() && filled($m->meeting_id) && preg_replace('/\D/', '', (string) $m->meeting_id) !== '') {
            $appJoinUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'zoom.join',
                now()->addHours(3),
                array_filter(['meeting' => $m->id, 'name' => $displayName, 'member' => $memberId]),
            );
        }

        return [
            'id' => $m->id,
            'title' => $m->title,
            // any row tied to this member — an app-join tap OR a Zoom-verified row
            'attended' => $memberId
                ? $m->attendances()->where('member_id', $memberId)->exists()
                : false,
            'description' => $m->description,
            'platform' => $m->platform,
            'join_url' => $m->join_url,
            'app_join_url' => $appJoinUrl,
            'meeting_id' => $m->meeting_id,
            'passcode' => $m->passcode,
            'host' => $m->host_name,
            'scheduled_at' => $m->scheduled_at->toIso8601String(),
            'duration_min' => $m->duration_min,
            'status' => $m->status(),
            'visibility' => $m->visibility,
        ];
    }
}
