<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support chat (Phase 4c) for the signed-in app identity (Member or Customer).
 * A user can open threads, list them, read a transcript, and reply. HQ answers
 * from the admin panel; each HQ reply lands in the user's inbox + push.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportService $support) {}

    /** GET /support/threads — the user's threads, newest activity first. */
    public function index(Request $request): JsonResponse
    {
        $threads = $this->ownedThreads($request)
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (SupportThread $t) => $this->presentThread($t));

        return response()->json(['data' => $threads]);
    }

    /** POST /support/threads — open a new thread with a first message. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = $this->support->userMessage(null, $request->user(), $data['body'], $data['subject'] ?? null);

        return response()->json(['data' => $this->presentThread($message->thread)], 201);
    }

    /** GET /support/threads/{thread} — transcript; marks support replies read. */
    public function show(Request $request, SupportThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $this->support->markOwnerRead($thread);

        return response()->json([
            'thread' => $this->presentThread($thread->fresh()),
            'messages' => $thread->messages()->orderBy('created_at')->get()
                ->map(fn (SupportMessage $m) => $this->presentMessage($m)),
        ]);
    }

    /** POST /support/threads/{thread}/messages — reply within a thread. */
    public function reply(Request $request, SupportThread $thread): JsonResponse
    {
        $this->authorizeThread($request, $thread);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = $this->support->userMessage($thread, $request->user(), $data['body']);

        return response()->json(['data' => $this->presentMessage($message)], 201);
    }

    /** GET /support/unread-count — total unread support replies across threads. */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->ownedThreads($request)->get()->sum(fn (SupportThread $t) => $t->ownerUnreadCount());

        return response()->json(['count' => $count]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    protected function ownedThreads(Request $request)
    {
        $user = $request->user();

        return SupportThread::where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey());
    }

    protected function authorizeThread(Request $request, SupportThread $thread): void
    {
        $user = $request->user();
        abort_unless(
            $thread->owner_type === $user->getMorphClass() && (int) $thread->owner_id === (int) $user->getKey(),
            404,
        );
    }

    protected function presentThread(SupportThread $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'last_message_at' => optional($t->last_message_at)->toIso8601String(),
            'unread' => $t->ownerUnreadCount(),
        ];
    }

    protected function presentMessage(SupportMessage $m): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,            // 'user' | 'support'
            'body' => $m->body,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }
}
