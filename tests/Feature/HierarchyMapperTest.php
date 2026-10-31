<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\Pincode;
use App\Services\Geo\HierarchyMapper;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Board 2026-10-31: dealers and distributors are mapped into the hierarchy by pincode. */
class HierarchyMapperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Services\Activity\ActivityLogger::$enabled = true;   // the seeder switches it off
        // 626123 is Head Office's own pincode (seeded HQ): dealers carrying it map straight to HQ,
        // so the local test dealers use the neighbouring Sivakasi pincodes.
        foreach ([
            ['626123', 'Sivakasi H.O', 'Sivakasi', 'Virudhunagar', 'Tamil Nadu'],
            ['626125', 'Sivakasi West', 'Sivakasi', 'Virudhunagar', 'Tamil Nadu'],
            ['626124', 'Sivakasi East', 'Sivakasi', 'Virudhunagar', 'Tamil Nadu'],
            ['626001', 'Virudhunagar H.O', 'Virudhunagar', 'Virudhunagar', 'Tamil Nadu'],
            ['625001', 'Madurai H.O', 'Madurai North', 'Madurai', 'Tamil Nadu'],
            ['560001', 'Bangalore G.P.O', 'Bangalore North', 'Bangalore', 'Karnataka'],
        ] as [$pin, $office, $taluka, $district, $state]) {
            Pincode::create(['pincode' => $pin, 'office' => $office, 'taluka' => $taluka, 'district' => $district, 'state' => $state]);
        }
    }

    protected function dealer(string $name, string $level, string $pin, ?int $source = null): Branch
    {
        return Branch::create(['name' => $name, 'level' => $level, 'pincode' => $pin, 'country' => 'IN', 'is_active' => true, 'source_branch_id' => $source]);
    }

    public function test_dealers_get_the_nearest_allowed_supplier_by_pincode_locality(): void
    {
        $hq = Branch::where('level', 'hq')->firstOrFail();
        $district = $this->dealer('Virudhunagar District', 'district', '626001');
        $taluk = $this->dealer('Sivakasi Taluka', 'taluk', '626125');
        $talukFar = $this->dealer('Madurai Taluka', 'taluk', '625001');
        $reseller = $this->dealer('Sivakasi Retailer', 'reseller', '626124');
        $subDealer = $this->dealer('Sivakasi Sub Dealer', 'sub_dealer', '626125');
        $wholesaler = $this->dealer('Bangalore Wholesaler', 'wholesaler', '560001');

        $mapper = app(HierarchyMapper::class);

        $r = $mapper->mapBranch($reseller);
        $this->assertSame($taluk->id, $r['branch']->id, 'same taluka beats same district; taluka level beats district level');
        $this->assertStringContainsString('same taluka', $r['reason']);

        $this->assertSame($taluk->id, $mapper->mapBranch($subDealer)['branch']->id, 'sub dealer: same pincode as the taluka dealer (retailer is a different pincode)');
        $this->assertSame($district->id, $mapper->mapBranch($taluk)['branch']->id, 'taluka buys from its district dealer');
        $this->assertSame($hq->id, $mapper->mapBranch($talukFar)['branch']->id, 'Madurai taluka: same state only is no locality → Head Office');
        $this->assertSame($hq->id, $mapper->mapBranch($wholesaler)['branch']->id, 'Karnataka: nothing local → Head Office');
        $this->assertSame($hq->id, $mapper->mapBranch($district)['branch']->id, 'district with no regional / zonal → Head Office');

        // apply through the command, logged, and re-running changes nothing
        $this->artisan('branches:auto-map --apply')->assertSuccessful();
        $this->assertSame($taluk->id, (int) $reseller->fresh()->source_branch_id);
        $this->assertSame($hq->id, (int) $wholesaler->fresh()->source_branch_id);
        $this->assertTrue(\App\Models\ActivityLog::where('action', 'auto_mapped')->where('subject_id', $reseller->id)->exists());
        $this->artisan('branches:auto-map')->expectsOutputToContain('0 would change supplier')->assertSuccessful();
    }

    public function test_distributors_without_a_branch_are_mapped_to_the_local_counter(): void
    {
        $taluk = $this->dealer('Sivakasi Taluka', 'taluk', '626125');
        $reseller = $this->dealer('Sivakasi Retailer', 'reseller', '626124');
        $district = $this->dealer('Virudhunagar District', 'district', '626001');

        $rank = \App\Models\Rank::where('depth', 0)->value('id');
        $mk = fn (string $code, string $pin, ?int $branch = null) => Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999), 'joined_on' => now(),
            'placement' => 'level', 'rank_id' => $rank, 'status' => 'active', 'pincode' => $pin, 'branch_id' => $branch,
        ]);
        $a = $mk('MA1', '626124');          // same pincode as the retailer
        $b = $mk('MB1', '626001');          // district town → district dealer (same pincode)
        $c = $mk('MC1', '560001');          // Karnataka → nobody
        $d = $mk('MD1', '626125', $district->id);   // already mapped → untouched by default

        $mapper = app(HierarchyMapper::class);
        $this->assertSame($reseller->id, $mapper->mapMember($a)['branch']->id);
        $this->assertSame($district->id, $mapper->mapMember($b)['branch']->id);
        $this->assertNull($mapper->mapMember($c)['branch']);

        $this->artisan('members:auto-map --apply')->assertSuccessful();
        $this->assertSame($reseller->id, (int) $a->fresh()->branch_id);
        $this->assertSame($district->id, (int) $b->fresh()->branch_id);
        $this->assertNull($c->fresh()->branch_id);
        $this->assertSame($district->id, (int) $d->fresh()->branch_id, 'already-mapped distributors keep their branch unless --all');
    }
}
