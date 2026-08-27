<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\StockTransferMargin;
use App\Models\User;
use App\Services\DealershipService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Dealership ladder (board 2026-08-26): Plans hid → branch level, the per-level
 * buys-from allow-list, Regional as a margin-earning seller, plan-driven branch
 * promotion, and the source audit command.
 */
class DealershipLadderTest extends TestCase
{
    use RefreshDatabase;

    protected function mk(string $name, string $level, ?int $source = null): Branch
    {
        return Branch::create(['name' => $name, 'country' => 'IN', 'level' => $level, 'source_branch_id' => $source, 'is_active' => true]);
    }

    public function test_ladder_order_and_hid_mapping(): void
    {
        $this->assertSame(['hq', 'regional', 'zonal', 'district', 'taluk', 'reseller', 'sub_dealer', 'wholesaler', 'area_dealer'], Branch::LEVELS);
        $this->assertSame('regional', Branch::levelForHid(1));
        $this->assertSame('taluk', Branch::levelForHid(4));
        $this->assertSame('reseller', Branch::levelForHid(5));
        $this->assertSame('sub_dealer', Branch::levelForHid(6));
        $this->assertSame('wholesaler', Branch::levelForHid(7));
        $this->assertSame('area_dealer', Branch::levelForHid(8));
        $this->assertNull(Branch::levelForHid(null));
        $this->assertNull(Branch::levelForHid(0));
    }

    public function test_buys_from_allow_list_per_level(): void
    {
        $hq = $this->mk('HQ', 'hq');
        $regional = $this->mk('Regional', 'regional');
        $zonal = $this->mk('Zonal', 'zonal');
        $district = $this->mk('District', 'district');
        $taluk = $this->mk('Taluka', 'taluk');
        $retailer = $this->mk('Retailer', 'reseller');
        $sub = $this->mk('Sub Dealer', 'sub_dealer');
        $wholesaler = $this->mk('Wholesaler', 'wholesaler');
        $area = $this->mk('Area', 'area_dealer');

        $ids = fn (Branch $b, bool $hq = false) => collect($b->sourceCandidates($hq)->pluck('id'))->sort()->values()->all();
        $of = fn (Branch ...$bs) => collect($bs)->pluck('id')->sort()->values()->all();

        $this->assertSame($of($hq), $ids($regional));
        $this->assertSame($of($hq, $regional), $ids($zonal));
        $this->assertSame($of($hq, $regional, $zonal), $ids($district));
        $this->assertSame($of($hq, $regional, $zonal, $district), $ids($taluk));
        $this->assertSame($of($hq, $regional, $zonal, $district, $taluk), $ids($retailer));
        $this->assertSame($of($hq, $taluk, $retailer), $ids($wholesaler));
        $this->assertSame($of($hq, $taluk, $retailer, $wholesaler), $ids($sub));
        $this->assertSame($of($hq, $regional, $zonal, $district, $taluk, $retailer, $wholesaler), $ids($area));
        // nobody ever buys from a Sub Dealer or an Area Distributor
        foreach ([$regional, $zonal, $district, $taluk, $retailer, $wholesaler, $sub, $area] as $b) {
            $this->assertNotContains($sub->id, $ids($b));
            $this->assertNotContains($area->id, $ids($b));
        }
    }

    public function test_regional_seller_earns_its_own_margin_and_leaves_earn_none(): void
    {
        $hq = $this->mk('HQ', 'hq');
        $regional = $this->mk('Regional', 'regional', $hq->id);
        $zonal = $this->mk('Zonal', 'zonal', $regional->id);
        $gold = CatalogProduct::create(['code' => 'DL-G', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        StockTransferMargin::create(['catalog_product_id' => $gold->id, 'regional_pct' => 1.5, 'zonal_pct' => 2]);

        $t = app(StockTransferService::class)->record([
            'source_branch_id' => $regional->id, 'destination_branch_id' => $zonal->id,
            'catalog_product_id' => $gold->id, 'weight' => 10, 'unit_rate' => 5000,
        ]);
        $this->assertSame('regional', $t->seller_level);
        $this->assertEquals(1.5, (float) $t->margin_pct);
        $this->assertEquals(750, (float) $t->margin_amount);

        $this->assertContains('regional', Branch::SELLER_LEVELS);
        $this->assertNotContains('sub_dealer', Branch::SELLER_LEVELS);
        $this->assertNotContains('area_dealer', Branch::SELLER_LEVELS);
    }

    public function test_billing_a_higher_dealership_plan_promotes_the_branch_but_never_downgrades(): void
    {
        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        $branch = $this->mk('Dealer', 'reseller');
        $member = Member::create([
            'member_code' => 'LJD001', 'name' => 'Dealer', 'phone' => '9000000002', 'joined_on' => now(),
            'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::first()->id, 'branch_id' => $branch->id,
        ]);
        User::create(['name' => 'Dealer', 'email' => 'ljd001@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $branch->id, 'member_code' => 'LJD001']);
        $taluka = Plan::create(['code' => 'P203X', 'hid' => 4, 'name' => ['en' => 'Taluka Dealership'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 500000, 'is_active' => true]);
        $retailer = Plan::create(['code' => 'P201X', 'hid' => 5, 'name' => ['en' => 'G5 Retailer'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 100000, 'is_active' => true]);
        $rd = Plan::create(['code' => 'P200X', 'hid' => null, 'name' => ['en' => 'RD'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 1000, 'is_active' => true]);

        $svc = app(DealershipService::class);
        $this->assertSame('taluk', $svc->applyPlanLevel($member, $taluka));
        $this->assertSame('taluk', $branch->fresh()->level);

        $this->assertNull($svc->applyPlanLevel($member, $retailer));   // lower plan → no downgrade
        $this->assertNull($svc->applyPlanLevel($member, $rd));         // not a dealership plan
        $this->assertSame('taluk', $branch->fresh()->level);
    }

    public function test_audit_command_lists_sources_outside_the_allow_list(): void
    {
        $hq = $this->mk('HQ', 'hq');
        $wholesaler = $this->mk('Wholesaler', 'wholesaler', $hq->id);
        $this->mk('Retailer OK', 'reseller', $hq->id);
        $this->mk('Retailer Broken', 'reseller', $wholesaler->id);   // reseller ← wholesaler is no longer allowed

        $rows = app(DealershipService::class)->auditSources();
        $this->assertCount(1, $rows);
        $this->assertSame('Retailer Broken', $rows[0]['branch']->name);
        $this->assertSame(['hq', 'regional', 'zonal', 'district', 'taluk'], $rows[0]['allowed']);

        $this->assertSame(1, Artisan::call('branches:audit-sources'));
        $this->assertStringContainsString('Retailer Broken', Artisan::output());
    }
}
