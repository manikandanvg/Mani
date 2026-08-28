<?php

namespace App\Http\Controllers;

use App\Models\TaskSubmission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a monthly-task proof photo (private disk) to a logged-in back-office user. */
class TaskProofController extends Controller
{
    public function show(TaskSubmission $submission): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user, 403);
        if ($user->isDistributor()) {
            $member = $submission->member;
            abort_unless($member && $member->member_code === $user->member_code, 403);
        }

        $path = ltrim((string) $submission->photo_path, '/');
        $disk = Storage::disk('local');
        abort_unless($path !== '' && $disk->exists($path), 404, 'Photo not found.');

        return $disk->response($path, basename($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
