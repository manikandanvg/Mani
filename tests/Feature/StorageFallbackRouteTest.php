<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /storage/{path} fallback (live has no public/storage symlink): the reply must
 * carry Content-Length — the L-BOX Pro's modem HTTP stack cannot read a
 * chunked body, so a missing length silences the box.
 */
class StorageFallbackRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_disk_file_is_served_with_content_length(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lbox-voice/abc.wav', str_repeat('x', 5000));

        $response = $this->get('/storage/lbox-voice/abc.wav');

        $response->assertOk();
        $this->assertSame('5000', $response->headers->get('Content-Length'));
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
    }

    public function test_missing_or_escaping_paths_404(): void
    {
        Storage::fake('public');

        $this->get('/storage/lbox-voice/nope.wav')->assertNotFound();
        $this->get('/storage/../.env')->assertNotFound();
    }
}
