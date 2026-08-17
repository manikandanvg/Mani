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
        $displayName = (string) ($user->name ?? 'LORDICL Member');
        // Rank-targeted meetings (item 7): only visible from the required TBP stage up.
        $myDepth = $user instanceof Member ? (int) ($user->rank?->depth ?? 0) : 0;

        $meetings = Meeting::published()
            ->whereIn('visibility', $levels)
            ->where(fn ($q) => $q->whereNull('min_rank_depth')->orWhere('min_rank_depth', '<=', $myDepth))
            ->where('scheduled_at', '>=', now()->subDays(7))
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Meeting $m) => $this->present($m, $displayName));

        // Group for the app: live + upcoming together (soonest first), past separate.
        return response()->json([
            'live' => $meetings->where('status', 'live')->values(),
            'upcoming' => $meetings->where('status', 'upcoming')->values(),
            'past' => $meetings->where('status', 'ended')->sortByDesc('scheduled_at')->values(),
        ]);
    }

    protected function present(Meeting $m, string $displayName = 'LORDICL Member'): array
    {
        // In-app join (2026-08-12): a signed Web-SDK page URL, minted per user so
        // their name shows in the meeting. Null when the SDK isn't configured or
        // the meeting has no numeric Zoom id — the app falls back to join_url.
        $zoom = app(\App\Services\Zoom\ZoomSdkService::class);
        $appJoinUrl = null;
        if ($zoom->configured() && filled($m->meeting_id) && preg_replace('/\D/', '', (string) $m->meeting_id) !== '') {
            $appJoinUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'zoom.join',
                now()->addHours(3),
                ['meeting' => $m->id, 'name' => $displayName],
            );
        }

        return [
            'id' => $m->id,
            'title' => $m->title,
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
