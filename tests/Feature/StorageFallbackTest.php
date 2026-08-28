<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * User 2026-08-29: photos / attachments showed locally but not on the live server (no
 * public/storage symlink there). /storage/{path} now falls through to Laravel and streams
 * the public-disk file — same URL the admin and the app already use.
 */
class StorageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_disk_files_are_served_through_the_storage_url_without_a_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attendance/selfie-1.jpg', 'JPEGDATA');

        $this->get('/storage/attendance/selfie-1.jpg')->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
        $this->get('/storage/attendance/missing.jpg')->assertNotFound();
        $this->get('/storage/../.env')->assertNotFound();
        $this->get('/storage/attendance/%2e%2e/%2e%2e/.env')->assertNotFound();
    }

    public function test_in_person_meetings_carry_a_venue_and_no_link_for_the_app(): void
    {
        $rank = Rank::firstOrCreate(['depth' => 0], ['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'target_bv' => 0]);
        $m = Member::create(['member_code' => 'LV1', 'name' => 'Live One', 'phone' => '9000000901', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank->id]);
        $box = Device::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Arena box', 'serial_no' => 'SN-ARENA']);
        Meeting::create(['title' => 'Monthly general meeting', 'platform' => 'lbox', 'device_id' => $box->id, 'scheduled_at' => now()->addDay(),
            'duration_min' => 90, 'visibility' => 'members', 'is_published' => true]);

        Sanctum::actingAs($m, ['*']);
        $row = collect($this->getJson('/api/v1/meetings')->assertOk()->json('upcoming'))->firstWhere('title', 'Monthly general meeting');

        $this->assertTrue($row['in_person']);
        $this->assertSame('Arena box', $row['venue']);
        $this->assertNull($row['join_url']);
        $this->assertFalse($row['sdk_join']);
    }
}
