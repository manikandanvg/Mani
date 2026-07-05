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
        $levels = $request->user() instanceof Member ? ['members', 'public'] : ['public'];

        $meetings = Meeting::published()
            ->whereIn('visibility', $levels)
            ->where('scheduled_at', '>=', now()->subDays(7))
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Meeting $m) => $this->present($m));

        // Group for the app: live + upcoming together (soonest first), past separate.
        return response()->json([
            'live' => $meetings->where('status', 'live')->values(),
            'upcoming' => $meetings->where('status', 'upcoming')->values(),
            'past' => $meetings->where('status', 'ended')->sortByDesc('scheduled_at')->values(),
        ]);
    }

    protected function present(Meeting $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'platform' => $m->platform,
            'join_url' => $m->join_url,
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
