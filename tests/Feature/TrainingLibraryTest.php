<?php

namespace Tests\Feature;

use App\Filament\Resources\DriveFolderResource\Pages\CreateDriveFolder;
use App\Filament\Resources\DriveFolderResource\Pages\EditDriveFolder;
use App\Filament\Resources\DriveFolderResource\RelationManagers\FilesRelationManager;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Member;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Community → Training Library (user 2026-08-29): HQ folders + uploads feed the app's Library screen. */
class TrainingLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->get('/admin/login');
    }

    public function test_hq_creates_a_public_folder_uploads_a_file_and_the_app_lists_it(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->admin)->test(CreateDriveFolder::class)
            ->fillForm(['name' => 'Scheme brochures', 'visibility' => 'public'])
            ->call('create')->assertHasNoFormErrors();
        $folder = DriveFolder::where('name', 'Scheme brochures')->firstOrFail();
        $this->assertSame($this->admin->id, $folder->owner_id);

        Livewire::actingAs($this->admin)->test(FilesRelationManager::class, ['ownerRecord' => $folder, 'pageClass' => EditDriveFolder::class])
            ->callTableAction('create', data: [
                'path' => UploadedFile::fake()->createWithContent('G10-brochure.pdf', '%PDF-1.4 ' . str_repeat('x', 4096)),
                'name' => 'G10 Gold brochure', 'visibility' => 'public',
            ])
            ->assertHasNoTableActionErrors();

        $file = DriveFile::firstOrFail();
        $this->assertSame($folder->id, $file->folder_id);
        $this->assertSame('local', $file->disk);
        $this->assertGreaterThan(0, $file->size);
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($this->admin)->get(route('library.file.admin', $file))->assertOk();

        // The app's Library sees the public folder and its file with a signed download link.
        $rank = Rank::query()->value('id');
        $m = Member::create(['member_code' => 'LIB1', 'name' => 'Lib One', 'phone' => '9000000777', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        Sanctum::actingAs($m, ['*']);
        $root = $this->getJson('/api/v1/library')->assertOk()->json();
        $this->assertSame('Scheme brochures', $root['folders'][0]['name']);
        $inside = $this->getJson('/api/v1/library?folder=' . $folder->id)->assertOk()->json();
        $this->assertSame('G10 Gold brochure', $inside['files'][0]['name']);
        $this->assertStringContainsString('signature=', $inside['files'][0]['download_url']);

        // Private folders never reach the app.
        DriveFolder::create(['name' => 'HQ internal', 'owner_id' => $this->admin->id, 'visibility' => 'private']);
        $this->assertCount(1, $this->getJson('/api/v1/library')->json('folders'));
    }

    public function test_menu_shows_training_library_and_hides_the_raw_files_list(): void
    {
        $this->actingAs($this->admin)->get('/admin/drive-folders')->assertSuccessful()->assertSee('Training Library');
        $this->assertFalse(\App\Filament\Resources\DriveFileResource::shouldRegisterNavigation());
        $this->assertSame('Training Library', \App\Filament\Resources\DriveFolderResource::getNavigationLabel());
    }
}
