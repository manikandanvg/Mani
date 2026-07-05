<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Training library (Phase 6b) — the "Learn" half of Live & Learn. Read-only view of
 * the HQ file drive (reuses drive_folders/drive_files), exposing only `public`
 * content. Member-only: training material is for the distributor network.
 *
 * Each file carries a short-lived SIGNED download URL (like the Document Vault) so the
 * app opens it directly in a viewer without juggling the bearer token.
 */
class LibraryController extends Controller
{
    public const LINK_TTL_MINUTES = 15;

    /** GET /library?folder=<id> — public folders + files at this level (root if omitted). */
    public function index(Request $request): JsonResponse
    {
        $this->member($request);

        $folder = null;
        if ($request->filled('folder')) {
            $folder = DriveFolder::where('visibility', 'public')->findOrFail((int) $request->query('folder'));
        }
        $parentId = $folder?->id;

        $folders = DriveFolder::where('visibility', 'public')
            ->where('parent_id', $parentId)
            ->orderBy('name')
            ->get()
            ->map(fn (DriveFolder $f) => ['id' => $f->id, 'name' => $f->name]);

        $files = DriveFile::where('visibility', 'public')
            ->where('folder_id', $parentId)
            ->orderBy('name')
            ->get()
            ->map(fn (DriveFile $f) => $this->presentFile($f));

        return response()->json([
            'folder' => $folder ? ['id' => $folder->id, 'name' => $folder->name] : null,
            'folders' => $folders,
            'files' => $files,
        ]);
    }

    /** GET /library/files/{id} (signed) — stream a public library file. */
    public function download(Request $request, int $id)
    {
        $file = DriveFile::where('visibility', 'public')->findOrFail($id);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $file->name);
    }

    protected function presentFile(DriveFile $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'mime' => $f->mime,
            'size' => (int) $f->size,
            'download_url' => URL::temporarySignedRoute(
                'api.library.file',
                now()->addMinutes(self::LINK_TTL_MINUTES),
                ['id' => $f->id],
            ),
        ];
    }

    protected function member(Request $request): Member
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'The training library is for distributors.');

        return $user;
    }
}
