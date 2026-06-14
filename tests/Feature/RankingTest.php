<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Services\NetworkService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RankingTest extends TestCase
{
    use RefreshDatabase;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // ranks 0..5 with tier_templates
    }

    protected function plan(array $attrs = []): Plan
    {
        $this->seq++;

        return Plan::create(array_merge([
            'code' => 'PL' . $this->seq, 'name' => ['en' => 'Plan ' . $this->seq], 'type' => 'digital',
            'min_value' => 0, 'allocation_pct' => 100, 'validity_months' => 11, 'level_com_duration' => 11,
            'is_active' => true,
        ], $attrs));
    }

    protected function member(string $code, ?int $uplineId): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . str_pad((string) (++$this->seq), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active', 'upline_id' => $uplineId,
        ]);
    }

    protected function bond(Member $m, Plan $plan, float $value, ?Carbon $date = null): Bond
    {
        return Bond::create([
            'member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => $date ?? now(),
            'value' => $value, 'epin_value' => $value, 'lvlcom_count' => 11, 'status' => 'active',
        ]);
    }

    protected function rankCode(Member $m): ?string
    {
        return $m->fresh()->rank?->code;
    }

    public function test_seed_has_six_tiers_with_templates(): void
    {
        $this->assertDatabaseCount('ranks', 6);
        $this->assertEquals([500000, 500000, 0, 0, 0], Rank::where('code', 'DISTRICT_DIRECTOR')->value('tier_template'));
        $this->assertEquals(50000, (float) Rank::where('code', 'TALUK_DIRECTOR')->value('target_bv'));
    }

    /** Balanced legs: two ≥5L legs + the 50k entry gate → District Director. */
    public function test_balanced_legs_promote_to_district(): void
    {
        $plan = $this->plan(['allocation_pct' => 100]);   // factor 1 → unpure_bv = value
        $a = $this->member('A', null);
        $b = $this->member('B', $a->id);
        $c = $this->member('C', $a->id);
        $this->bond($a, $plan, 50000);
        $this->bond($b, $plan, 500000);
        $this->bond($c, $plan, 500000);

        app(NetworkService::class)->recomputeAll();

        $this->assertEquals('DISTRICT_DIRECTOR', $this->rankCode($a));
        $this->assertEquals('MEMBER', $this->rankCode($b));   // no downline → fails entry gate
    }

    /** Entry gate needs BOTH self ≥50k AND a direct downline ≥50k. */
    public function test_entry_gate_requires_self_and_one_direct(): void
    {
        $plan = $this->plan(['allocation_pct' => 100]);
        $x = $this->member('X', null);
        $y = $this->member('Y', $x->id);
        $this->bond($x, $plan, 50000);
        $weakY = $this->bond($y, $plan, 40000);     // below 50k → gate fails

        app(NetworkService::class)->recomputeAll();
        $this->assertEquals('MEMBER', $this->rankCode($x));

        // lift Y to 50k → X now qualifies for Taluk (entry), but not enough legs for District
        $weakY->update(['value' => 50000, 'epin_value' => 50000]);
        app(NetworkService::class)->recomputeAll();
        $this->assertEquals('TALUK_DIRECTOR', $this->rankCode($x));
    }

    /** unpure_bv applies the plan factor and excludes non-counting plans. */
    public function test_unpure_bv_factor_and_exclusion(): void
    {
        $full = $this->plan(['allocation_pct' => 100]);                       // ×1
        $dealer = $this->plan(['allocation_pct' => 80]);                      // ×0.2
        $excluded = $this->plan(['allocation_pct' => 100, 'counts_for_rank' => false]); // ×0
        $area = $this->plan(['allocation_pct' => 10, 'rank_factor' => 0.10]); // ×0.10 override

        $m = $this->member('M', null);
        $this->bond($m, $full, 100000);      // +100000
        $this->bond($m, $dealer, 100000);    // +20000
        $this->bond($m, $excluded, 100000);  // +0
        $this->bond($m, $area, 100000);      // +10000

        app(NetworkService::class)->recomputeUnpureBv();

        $this->assertEquals(130000, (float) $m->fresh()->unpure_bv);
        // bv (face) counts every active bond's value
        app(NetworkService::class)->recomputePersonalBv();
        $this->assertEquals(400000, (float) $m->fresh()->bv);
    }

    /** A bond past its plan validity is closed and stops counting toward BV. */
    public function test_expired_bond_is_closed_and_excluded(): void
    {
        $plan = $this->plan(['allocation_pct' => 100, 'level_com_duration' => 11]);
        $m = $this->member('M', null);
        $old = $this->bond($m, $plan, 70000, now()->subMonthsNoOverflow(12)); // age 12 ≥ 11 → expire
        $this->bond($m, $plan, 30000, now());                                  // active

        app(NetworkService::class)->recomputeAll();

        $this->assertEquals('closed', $old->fresh()->status);
        $this->assertEquals(30000, (float) $m->fresh()->bv);   // only the active bond
    }
}
