<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6b — training library (read-only view of public drive content).
 */
class LibraryTest extends TestCase
{
    use RefreshDatabase;

    protected Member $member;
    protected User $hq;

    protected function setUp(): void
    {
        parent::setUp();
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $this->member = Member::create([
            'member_code' => 'LB1', 'name' => 'Learner', 'phone' => '9000000070',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active',
        ]);
        $this->hq = User::create(['name' => 'HQ', 'email' => 'lib@lordicl.test', 'password' => bcrypt('x')]);
    }

    protected function file(array $attrs = []): DriveFile
    {
        return DriveFile::create(array_merge([
            'owner_id' => $this->hq->id, 'name' => 'guide.pdf', 'disk' => 'local',
            'path' => 'lib/guide.pdf', 'mime' => 'application/pdf', 'size' => 1234, 'visibility' => 'public',
        ], $attrs));
    }

    public function test_member_only(): void
    {
        Sanctum::actingAs(Customer::create(['phone' => '9123000999']), ['*']);
        $this->getJson('/api/v1/library')->assertStatus(403);
    }

    public function test_lists_public_content_and_hides_private(): void
    {
        $pubFolder = DriveFolder::create(['name' => 'Onboarding', 'owner_id' => $this->hq->id, 'visibility' => 'public']);
        DriveFolder::create(['name' => 'Secret', 'owner_id' => $this->hq->id, 'visibility' => 'private']);
        $this->file(['name' => 'welcome.pdf']);
        $this->file(['name' => 'hidden.pdf', 'visibility' => 'private']);

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson('/api/v1/library')->assertOk()
            ->assertJsonStructure(['folder', 'folders', 'files' => [['id', 'name', 'mime', 'size', 'download_url']]]);

        $this->assertEqualsCanonicalizing(['Onboarding'], collect($res->json('folders'))->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['welcome.pdf'], collect($res->json('files'))->pluck('name')->all());
        $this->assertNull($res->json('folder'));
    }

    public function test_navigates_into_a_folder(): void
    {
        $folder = DriveFolder::create(['name' => 'Sales', 'owner_id' => $this->hq->id, 'visibility' => 'public']);
        $this->file(['name' => 'pitch.pdf', 'folder_id' => $folder->id]);
        $this->file(['name' => 'root.pdf']); // root-level, should NOT appear inside the folder

        Sanctum::actingAs($this->member, ['*']);
        $res = $this->getJson("/api/v1/library?folder={$folder->id}")->assertOk();

        $this->assertSame('Sales', $res->json('folder.name'));
        $this->assertEqualsCanonicalizing(['pitch.pdf'], collect($res->json('files'))->pluck('name')->all());
    }

    public function test_signed_download_streams_public_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('lib/guide.pdf', '%PDF-1.4 fake');
        $file = $this->file();

        Sanctum::actingAs($this->member, ['*']);
        $url = $this->getJson('/api/v1/library')->json('files.0.download_url');
        $this->assertNotEmpty($url);

        $path = str_replace(config('app.url'), '', $url);
        $this->get($path)->assertOk()->assertHeader('content-disposition');

        // Unsigned access is rejected.
        $this->get("/api/v1/library/files/{$file->id}")->assertStatus(403);
    }

    public function test_cannot_download_private_file_even_if_signed(): void
    {
        Storage::fake('local');
        $file = $this->file(['visibility' => 'private']);

        $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'api.library.file', now()->addMinutes(15), ['id' => $file->id]
        );
        $this->get(str_replace(config('app.url'), '', $signed))->assertStatus(404);
    }
}
