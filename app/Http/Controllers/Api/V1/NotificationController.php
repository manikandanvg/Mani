<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * App inbox (Phase 4a) — the unified message center. Works for both identities:
 * Member and Customer are each Notifiable, so `$request->user()->notifications()`
 * returns the right feed regardless of token type.
 */
class NotificationController extends Controller
{
    /** GET /notifications — paginated inbox, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = $request->user()->notifications()
            ->paginate(20)
            ->through(fn (DatabaseNotification $n) => $this->present($n));

        return response()->json($rows);
    }

    /** GET /notifications/unread-count — drives the inbox badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /notifications/{id}/read — mark a single notification read. */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $n = $request->user()->notifications()->whereKey($id)->firstOrFail();
        if ($n->read_at === null) {
            $n->markAsRead();
        }

        return response()->json(['ok' => true, 'notification' => $this->present($n->fresh())]);
    }

    /** POST /notifications/read-all — clear the badge. */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }

    /** Flatten a stored notification into the shape the app renders. */
    protected function present(DatabaseNotification $n): array
    {
        $data = $n->data ?? [];

        return [
            'id' => $n->id,
            'category' => $data['category'] ?? 'system',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'route' => $data['route'] ?? null,
            'data' => $data['data'] ?? [],
            'read' => $n->read_at !== null,
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }
}
