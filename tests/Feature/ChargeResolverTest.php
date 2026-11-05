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

    public function test_cash_to_gold_reverses_making_and_wastage_the_legacy_way(): void
    {
        // 0..100g gold: making 5% + wastage 3% = 8% total.
        ChargeBracket::create(['material' => 'gold', 'wt_from' => 0, 'wt_to' => 100, 'making_pct' => 5, 'wastage_pct' => 3]);

        // ₹1,00,000 at ₹5,000/g -> approx 20g (picks the slab) -> metal = 1,00,000 × 100/108
        // = ₹92,592.59 (charges ₹7,407.41) -> 18.5185 g. Metal + 8 % of it = the cash again.
        $out = app(ChargeResolver::class)->cashToGold(100000, 5000);

        $this->assertEquals(20.0, $out['gross_grams']);
        $this->assertEquals(7407.41, $out['charge']);
        $this->assertEqualsWithDelta(18.5185, $out['net_grams'], 0.0001);
    }

    /** User 2026-11-05: the live slabs and a real legacy QR — ₹25,000 Sub Dealer gold share = 1.8159 g @ ₹12,730. */
    public function test_grams_for_a_gst_inclusive_worth_match_the_legacy_qr(): void
    {
        $this->legacySlabs();

        $this->assertEqualsWithDelta(1.8159, app(ChargeResolver::class)->gramsForWorth(25000, 12730), 0.0001);
        $this->assertEqualsWithDelta(3.6317, app(ChargeResolver::class)->gramsForWorth(50000, 12730), 0.0001);
    }

    /** A weight between two slabs (0.1000 < g < 0.1010) takes the next slab up; beyond the last slab there is still no charge. */
    public function test_a_weight_in_a_gap_between_slabs_is_never_charge_free(): void
    {
        $this->legacySlabs();
        $r = app(ChargeResolver::class);

        $this->assertEquals(20.0, $r->forWeight('gold', 0.1)['making_pct']);
        $this->assertEquals(15.0, $r->forWeight('gold', 0.1005)['making_pct']);    // gap → 0.101–0.300 slab
        $this->assertNull($r->forWeight('gold', 5000)['bracket_id']);              // heavier than every slab: unchanged, no charge
        $this->assertEquals(20.0, $r->forWeight('gold', 0.0001)['making_pct']);    // lighter than the first
        $this->assertNull($r->forWeight('silver', 5)['bracket_id']);               // no slab for the metal at all
        $this->assertNull($r->forWeight('gold', 0)['bracket_id']);
    }

    protected function legacySlabs(): void
    {
        foreach ([[0.001, 0.1, 20], [0.101, 0.3, 15], [0.301, 0.5, 10], [0.501, 1, 2.5], [1.001, 8, 2.5], [8.001, 800, 2.5]] as [$from, $to, $pct]) {
            ChargeBracket::create(['material' => 'gold', 'wt_from' => $from, 'wt_to' => $to, 'making_pct' => $pct, 'wastage_pct' => $pct]);
        }
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
