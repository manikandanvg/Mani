<?php

namespace Tests\Feature;

use App\Filament\Resources\FirmwareResource\Pages\CreateFirmware;
use App\Models\Branch;
use App\Models\Firmware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OTA firmware upload. The old `mimetypes:application/octet-stream` rule
 * rejected real builds, because `.bin` has no registered media type and the
 * browser reports whatever the OS says. It is replaced by an extension check
 * plus the ESP32 image header — flashing the wrong file to a remote fleet is
 * unrecoverable without a site visit, so the check has to be about content.
 */
class FirmwareUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Role::firstOrCreate(['name' => 'super_admin']);
        $branch = Branch::create(['name' => 'HQ', 'country' => 'IN', 'is_active' => true]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'fw@lordicl', 'password' => bcrypt('x'),
            'status' => 'active', 'branch_id' => $branch->id,
        ]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    /** An ESP32 image: 0xE9 magic byte, then payload. */
    private function espImage(string $name = 'firmware.bin'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xE9" . str_repeat("\x00", 512));
    }

    public function test_a_real_esp32_bin_uploads_whatever_mime_the_browser_reports(): void
    {
        Livewire::test(CreateFirmware::class)
            ->fillForm([
                'board_type' => 'pro',
                'version' => '1.0.25',
                'path' => [$this->espImage()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('1.0.25', Firmware::first()?->version);
    }

    public function test_firmware_elf_is_refused_by_the_header_check(): void
    {
        // firmware.elf sits next to firmware.bin in .pio/build — easy to pick by
        // mistake, and it would brick a box. ELF starts 0x7F "ELF".
        Livewire::test(CreateFirmware::class)
            ->fillForm([
                'board_type' => 'pro',
                'version' => '1.0.25',
                'path' => [UploadedFile::fake()->createWithContent('firmware.bin', "\x7fELF" . str_repeat("\x00", 512))],
            ])
            ->call('create')
            ->assertHasFormErrors(['path']);

        $this->assertSame(0, Firmware::count());
    }

    public function test_a_non_bin_file_is_refused(): void
    {
        Livewire::test(CreateFirmware::class)
            ->fillForm([
                'board_type' => 'lite',
                'version' => '1.0.12',
                'path' => [UploadedFile::fake()->createWithContent('notes.txt', "\xE9 still not firmware")],
            ])
            ->call('create')
            ->assertHasFormErrors(['path']);

        $this->assertSame(0, Firmware::count());
    }
}
