<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fleet relocation: HQ moves every box to a new API base by setting
 * LBOX_DEVICE_API_URL. The heartbeat echoes it, firmware >= 1.0.12 saves it to
 * NVS and reboots onto it — the only way to take a LAN-provisioned box to the
 * cloud without a site visit.
 *
 * The dangerous failure is the box being pointed somewhere it cannot reach, so
 * the rule is: send NOTHING unless HQ set it deliberately.
 */
class LboxFleetRelocationTest extends TestCase
{
    use RefreshDatabase;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::create(['name' => 'Rajapalayam', 'country' => 'IN', 'is_active' => true]);
        $this->device = Device::create([
            'name' => 'Counter Box',
            'serial_no' => 'LBX-LITE-0001',
            'board_type' => 'lite',
            'branch_id' => $branch->id,
            'status' => 'active',   // already paired — relocation only affects live boxes
        ]);
        Sanctum::actingAs($this->device, ['device']);
    }

    public function test_heartbeat_sends_no_api_url_until_hq_sets_one(): void
    {
        config()->set('lbox.device_api_url', null);

        $this->postJson('/api/device/v1/heartbeat', ['battery_pct' => 88])
            ->assertOk()
            ->assertJsonPath('api_url', null);
    }

    public function test_blank_config_is_normalised_to_null_not_an_empty_string(): void
    {
        // An empty .env line must not read as "move to nowhere" — the box
        // compares against its own URL and '' would look like a change.
        config()->set('lbox.device_api_url', '');

        $this->postJson('/api/device/v1/heartbeat', [])
            ->assertOk()
            ->assertJsonPath('api_url', null);
    }

    public function test_heartbeat_publishes_the_new_base_once_hq_sets_it(): void
    {
        config()->set('lbox.device_api_url', 'https://next.lordicl.com/api/device/v1');

        $this->postJson('/api/device/v1/heartbeat', ['battery_pct' => 88, 'rssi' => -60])
            ->assertOk()
            ->assertJsonPath('api_url', 'https://next.lordicl.com/api/device/v1')
            // relocation must not disturb the rest of the heartbeat contract
            ->assertJsonStructure(['ok', 'pending_announcements', 'ota_available', 'volume', 'volume_ver']);
    }

    public function test_relocation_does_not_invalidate_the_device_token(): void
    {
        // The token belongs to the device row, not the host it was issued from,
        // so a box that follows the URL keeps working without re-pairing.
        config()->set('lbox.device_api_url', 'https://next.lordicl.com/api/device/v1');

        $this->postJson('/api/device/v1/heartbeat', [])->assertOk();

        $this->assertSame('active', $this->device->fresh()->status);
        $this->getJson('/api/device/v1/announcements')->assertOk();
    }
}
