<?php

namespace App\Http\Controllers;

use App\Models\BranchOrderAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stock-order payment receipt to an authorised back-office user (board
 * 2026-08-26). Replaces direct /storage/order-receipts/… links, which 404 wherever the
 * public/storage symlink is missing (the live server) and exposed bank slips to anyone
 * with the URL. Serves from whichever disk holds the file: the private `local` disk for
 * new uploads, the `public` disk for receipts recorded before this change.
 */
class OrderAttachmentController extends Controller
{
    public function show(BranchOrderAttachment $attachment): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user, 403);

        // Distributors may see receipts on their own branch's orders and on orders
        // placed WITH their branch (they are the supplier); HQ / admins see all.
        if ($user->isDistributor()) {
            $order = $attachment->orderRequest()->with('branch')->first();
            $mine = $order && (int) $order->branch_id === (int) $user->branch_id;
            $supplying = $order && $order->branch && (int) $order->branch->source_branch_id === (int) $user->branch_id;
            abort_unless($mine || $supplying, 403);
        }

        $path = ltrim((string) $attachment->path, '/');
        foreach (array_unique([$attachment->diskName(), 'local', 'public']) as $diskName) {
            $disk = Storage::disk($diskName);
            if ($path !== '' && $disk->exists($path)) {
                $name = $attachment->original_name ?: basename($path);
                $mime = $attachment->mime ?: ($disk->mimeType($path) ?: 'application/octet-stream');

                return $disk->response($path, $name, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'private, max-age=600',
                ]);
            }
        }

        abort(404, 'Receipt file not found on the server.');
    }
}
