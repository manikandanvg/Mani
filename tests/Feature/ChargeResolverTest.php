<?php

namespace Tests\Feature;

use App\Models\ChargeBracket;
use App\Models\LiveRate;
use App\Services\Charges\ChargeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_weight_picks_the_tightest_matching_slab(): void
    {
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 1000, 'making_pct' => 10, 'wastage_pct' => 2]);
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 50, 'making_pct' => 5, 'wastage_pct' => 3]);

        $r = app(ChargeResolver::class)->forWeight('gold', 20);

        $this->assertEquals(5, $r['making_pct']);   // narrower 0..50 slab wins
        $this->assertEquals(3, $r['wastage_pct']);
    }

    public function test_no_bracket_means_no_charge(): void
    {
        $r = app(ChargeResolver::class)->forWeight('gold', 999);
        $this->assertEquals(0, $r['making_pct']);
        $this->assertEquals(0, $r['wastage_pct']);
        $this->assertNull($r['bracket_id']);
    }

    public function test_cash_to_gold_deducts_making_and_wastage(): void
    {
        // 0..100g gold: making 5% + wastage 3% = 8% total.
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 100, 'making_pct' => 5, 'wastage_pct' => 3]);

        // ₹1,00,000 at ₹5,000/g -> gross 20g (matches slab) -> charge 8% = ₹8,000 -> net 18.4g.
        $out = app(ChargeResolver::class)->cashToGold(100000, 5000);

        $this->assertEquals(20.0, $out['gross_grams']);
        $this->assertEquals(8000.0, $out['charge']);
        $this->assertEqualsWithDelta(18.4, $out['net_grams'], 0.0001);
    }

    public function test_cash_to_gold_uses_live_rate_when_no_rate_passed(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'source' => 'manual', 'effective_at' => now()]);

        $out = app(ChargeResolver::class)->cashToGold(60000);   // no bracket -> 0% charge
        $this->assertEquals(10.0, $out['gross_grams']);          // 60000 / 6000
        $this->assertEquals(10.0, $out['net_grams']);
    }

    public function test_zero_rate_is_safe(): void
    {
        $out = app(ChargeResolver::class)->cashToGold(100000, 0);
        $this->assertEquals(0.0, $out['net_grams']);
    }
}
