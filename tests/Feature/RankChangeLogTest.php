<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RankChange;
use App\Services\NetworkService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * User 2026-11-04: every TBP-stage shift is registered with its date (rank_changes), and the
 * achievement dates of existing distributors are reconstructed from bond history (ranks:calibrate).
 */
class RankChangeLogTest extends TestCase
{
    use RefreshDatabase;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // ranks 0..5 with tier_templates
    }

    protected function plan(): Plan
    {
        $this->seq++;

        return Plan::create([
            'code' => 'PL' . $this->seq, 'name' => ['en' => 'Plan ' . $this->seq], 'type' => 'digital',
            'min_value' => 0, 'allocation' => 100, 'allocation_bv' => 100, 'validity_months' => 24, 'level_com_duration' => 24,
            'is_active' => true,
        ]);
    }

    protected function member(string $code, ?int $uplineId): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . str_pad((string) (++$this->seq), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active', 'upline_id' => $uplineId,
        ]);
    }

    protected function bond(Member $m, Plan $plan, float $value, string $date): Bond
    {
        return Bond::create([
            'member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => $date,
            'value' => $value, 'epin_value' => $value, 'lvlcom_count' => 24, 'status' => 'active',
        ]);
    }

    public function test_recompute_registers_promotions_and_demotions_with_the_date(): void
    {
        $plan = $this->plan();
        $entry = (float) Rank::where('depth', 1)->value('target_bv');
        $u = $this->member('U', null);
        $c = $this->member('C', $u->id);
        $this->bond($u, $plan, $entry, '2026-10-01');
        $childBond = $this->bond($c, $plan, $entry, '2026-10-15');

        Carbon::setTestNow('2026-11-04 10:00:00');
        app(NetworkService::class)->recomputeAll();

        $row = RankChange::where('member_id', $u->id)->firstOrFail();
        $this->assertEquals('up', $row->direction);
        $this->assertEquals(1, $row->to_depth);
        $this->assertEquals('recompute', $row->source);
        $this->assertEquals('2026-11-04', $row->changed_at->toDateString());
        $this->assertEquals(Rank::where('depth', 1)->value('id'), $u->fresh()->rank_id);

        // the child's bond is cancelled → the upline loses the entry gate → a demotion row
        $childBond->update(['status' => 'cancelled']);
        Carbon::setTestNow('2026-11-05 10:00:00');
        app(NetworkService::class)->recomputeAll();

        $down = RankChange::where('member_id', $u->id)->where('direction', 'down')->firstOrFail();
        $this->assertEquals(0, $down->to_depth);
        $this->assertEquals('2026-11-05', $down->changed_at->toDateString());
        Carbon::setTestNow();
    }

    public function test_calibrate_reconstructs_the_achievement_date_from_bond_history(): void
    {
        $plan = $this->plan();
        $entry = (float) Rank::where('depth', 1)->value('target_bv');
        $u = $this->member('U', null);
        $c = $this->member('C', $u->id);
        $this->bond($u, $plan, $entry, '2026-03-10');
        $this->bond($c, $plan, $entry, '2026-05-20');   // the day U first qualifies (own BV + one qualified direct)

        app(NetworkService::class)->recomputeAll();   // live rank today
        RankChange::query()->delete();                 // pretend nothing was ever registered

        $this->artisan('ranks:calibrate', ['--apply' => true, '--upto' => '2026-11-04'])->assertSuccessful();

        $row = RankChange::where('member_id', $u->id)->where('source', 'calibration')->where('to_depth', 1)->firstOrFail();
        $this->assertEquals('2026-05-20', $row->changed_at->toDateString());
        $this->assertEquals('up', $row->direction);
        $this->assertEquals(0, RankChange::where('member_id', $c->id)->count(), 'the child never ranked');
    }
}
