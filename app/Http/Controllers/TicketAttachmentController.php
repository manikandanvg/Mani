<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one support-ticket attachment (board phase 2, 2026-08-28: every new ticket
 * must carry a photo/file). Files live on the PRIVATE local disk and are served only
 * to logged-in back-office users — dealers for their own branch's tickets, HQ and
 * support for all — so nothing depends on the public/storage symlink.
 */
class TicketAttachmentController extends Controller
{
    public function show(SupportTicket $ticket, int $index): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user, 403);

        if ($user->isDistributor()) {
            $mine = (int) $ticket->branch_id === (int) $user->branch_id || (int) $ticket->opened_by === (int) $user->id;
            abort_unless($mine, 403);
        }

        $path = ltrim((string) (($ticket->attachments ?? [])[$index] ?? ''), '/');
        abort_if($path === '', 404);

        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);
            if ($disk->exists($path)) {
                return $disk->response($path, basename($path), [
                    'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
                    'Cache-Control' => 'private, max-age=600',
                ]);
            }
        }

        abort(404, 'Attachment not found on the server.');
    }
}
