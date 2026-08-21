<?php

namespace Tests\Feature;

use App\Models\Pincode;
use App\Services\Geo\PincodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pincode → State/District/Taluka (board phase-1, 2026-08-21): local master
 * first, one-time India Post API fetch for unknown PINs, cached thereafter.
 */
class PincodeLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_master_answers_without_the_api(): void
    {
        Http::fake();
        Pincode::create(['pincode' => '626117', 'office' => 'Rajapalayam', 'taluka' => 'Rajapalayam',
            'district' => 'Virudhunagar', 'state' => 'Tamil Nadu']);

        $geo = app(PincodeService::class)->lookup('626117');

        $this->assertSame('Tamil Nadu', $geo['state']);
        $this->assertSame('Virudhunagar', $geo['district']);
        $this->assertSame(['Rajapalayam'], $geo['talukas']);
        Http::assertNothingSent();
    }

    public function test_unknown_pin_is_fetched_once_and_cached(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response([[
                'Status' => 'Success',
                'PostOffice' => [
                    ['Name' => 'Anna Nagar', 'Block' => 'Egmore', 'District' => 'Chennai', 'State' => 'Tamil Nadu'],
                    ['Name' => 'Shenoy Nagar', 'Block' => 'Egmore', 'District' => 'Chennai', 'State' => 'Tamil Nadu'],
                ],
            ]]),
        ]);

        $svc = app(PincodeService::class);
        $geo = $svc->lookup('600040');

        $this->assertSame('Chennai', $geo['district']);
        $this->assertDatabaseCount('pincodes', 2);
        $this->assertDatabaseHas('pincodes', ['pincode' => '600040', 'office' => 'Anna Nagar', 'source' => 'api']);

        // second lookup answers from the cached master — no second HTTP call
        $svc->lookup('600040');
        Http::assertSentCount(1);
    }

    public function test_seeded_pin_without_taluka_is_enriched_once_from_the_api(): void
    {
        Http::fake([
            'api.postalpincode.in/*' => Http::response([[
                'Status' => 'Success',
                'PostOffice' => [['Name' => 'Kothimir B.O', 'Block' => 'Asifabad', 'District' => 'Kumuram Bheem', 'State' => 'Telangana']],
            ]]),
        ]);
        // the data.gov.in bulk seed carries district/state but no taluk
        Pincode::create(['pincode' => '504273', 'office' => 'Kothimir B.O',
            'district' => 'Kumuram Bheem', 'state' => 'Telangana', 'source' => 'import']);

        $svc = app(PincodeService::class);
        $geo = $svc->lookup('504273');

        $this->assertSame(['Asifabad'], $geo['talukas']);
        $this->assertDatabaseHas('pincodes', ['pincode' => '504273', 'office' => 'Kothimir B.O', 'taluka' => 'Asifabad']);

        // enrichment happens once — repeat lookups stay local
        $svc->lookup('504273');
        Http::assertSentCount(1);
    }

    public function test_invalid_or_unknown_pin_returns_null(): void
    {
        Http::fake(['api.postalpincode.in/*' => Http::response([['Status' => 'Error', 'PostOffice' => null]])]);

        $svc = app(PincodeService::class);
        $this->assertNull($svc->lookup('12345'));      // not 6 digits — no API call
        $this->assertNull($svc->lookup('999999'));     // API knows nothing
    }
}
