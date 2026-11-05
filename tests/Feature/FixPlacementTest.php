<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\SalesInvoice;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * User 2026-11-05: from 2026-11-02 distributors keyed a wrong Senior Distributor (placement)
 * on the Sales screen — `members:fix-placement` moves the member under the right senior and
 * moves the frozen level-1 upline and the IC (own bills + downline bills) with them.
 *
 *   ROOT ─┬─ WRONG ── X ── Y        X was referred by RIGHT but placed under WRONG
 *         └─ RIGHT
 */
class FixPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected array $m = [];

    protected Plan $plan;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seed(DatabaseSeeder::class);

        $this->branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $this->plan = Plan::create(['code' => 'TPL', 'name' => ['en' => 'G5'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 0,
            'is_redeem' => true, 'is_contract' => true, 'is_active' => true, 'validity_months' => 24, 'allocation' => 50,
            'ic_schedule' => [6, 1.8, 0.6, 0.6, 0.6, 0.48, 0.48, 0.48, 0.48, 0.48]]);
        $rank = Rank::where('depth', 0)->value('id');
        $mk = function (string $code, ?string $up, ?string $ref = null, string $joined = '2026-01-01') use ($rank) {
            $this->m[$code] = Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
                'joined_on' => $joined, 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank,
                'upline_id' => $up ? $this->m[$up]->id : null, 'referrer_id' => $ref ? $this->m[$ref]->id : ($up ? $this->m[$up]->id : null)]);
            // every upline has an active bond, so earning potential is open
            Bond::create(['member_id' => $this->m[$code]->id, 'plan_id' => $this->plan->id, 'branch_id' => $this->branch->id, 'bond_date' => '2026-01-01',
                'value' => 500000, 'epin_value' => 1000000, 'invoice_no' => 'OLD-' . $code, 'status' => 'active']);
        };
        $mk('ROOT', null);
        $mk('WRONG', 'ROOT');
        $mk('RIGHT', 'ROOT');
        $mk('X', 'WRONG', 'RIGHT', '2026-11-03');
        $mk('Y', 'X', null, '2026-11-04');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A ₹1,00,000 bill for $buyer with IC issued up its CURRENT (wrong) chain. */
    protected function bill(string $buyer, string $inv, array $statuses = []): Bond
    {
        SalesInvoice::create(['invoice_no' => $inv, 'date' => '2026-11-04', 'branch_id' => $this->branch->id,
            'customer_member_id' => $this->m[$buyer]->id, 'cross_total' => 100000, 'grand_total' => 103000, 'fx_rate' => 1]);
        $bond = Bond::create(['member_id' => $this->m[$buyer]->id, 'plan_id' => $this->plan->id, 'branch_id' => $this->branch->id, 'bond_date' => '2026-11-04',
            'value' => 50000, 'epin_value' => 103000, 'invoice_no' => $inv, 'status' => 'active', 'upline_member_id' => $this->m[$buyer]->upline_id]);
        $level = 1;
        for ($u = $this->m[$buyer]->upline; $u; $u = $u->upline, $level++) {
            CommissionLedger::create(['type' => 'IC', 'member_id' => $u->id, 'from_member_id' => $this->m[$buyer]->id, 'bond_id' => $bond->id,
                'invoice_no' => $inv, 'level' => $level, 'amount' => round(100000 * $this->plan->ic_schedule[$level - 1] / 100, 2),
                'status' => $statuses[$level] ?? 'pending', 'earned_on' => '2026-11-04', 'branch_id' => $this->branch->id]);
        }

        return $bond;
    }

    protected function ic(string $inv): array
    {
        return CommissionLedger::where('invoice_no', $inv)->where('type', 'IC')->orderBy('level')->get()
            ->map(fn ($r) => [$r->level, Member::find($r->member_id)->member_code, (float) $r->amount, $r->status])->all();
    }

    public function test_lists_and_moves_member_with_frozen_upline_and_ic(): void
    {
        $bx = $this->bill('X', 'INV-X1');
        $this->bill('Y', 'INV-Y1');

        $this->artisan('members:fix-placement')->expectsOutputToContain('1 distributor(s) since 2026-11-02')->assertSuccessful();

        // dry run writes nothing
        $this->artisan('members:fix-placement', ['--member' => ['X']])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame($this->m['WRONG']->id, $this->m['X']->fresh()->upline_id);

        $this->artisan('members:fix-placement', ['--all' => true, '--apply' => true])->expectsOutputToContain('MOVED')->assertSuccessful();

        $this->assertSame($this->m['RIGHT']->id, $this->m['X']->fresh()->upline_id);
        $this->assertSame($this->m['RIGHT']->id, $bx->fresh()->upline_member_id);
        // X's own bill: level 1 RIGHT (6 %), level 2 ROOT
        $this->assertSame([[1, 'RIGHT', 6000.0, 'pending'], [2, 'ROOT', 1800.0, 'pending']], $this->ic('INV-X1'));
        // Y's bill: level 1 X stays, level 2 RIGHT, level 3 ROOT
        $this->assertSame([[1, 'X', 6000.0, 'pending'], [2, 'RIGHT', 1800.0, 'pending'], [3, 'ROOT', 600.0, 'pending']], $this->ic('INV-Y1'));
        // Y's own frozen upline untouched
        $this->assertSame($this->m['X']->id, Bond::where('invoice_no', 'INV-Y1')->value('upline_member_id'));

        // idempotent
        $this->artisan('members:fix-placement', ['--member' => ['X'], '--apply' => true])->expectsOutputToContain('already right')->assertSuccessful();
        $this->assertCount(2, $this->ic('INV-X1'));
    }

    public function test_paid_ic_on_the_wrong_upline_is_reported_not_touched(): void
    {
        $this->bill('X', 'INV-X2', [1 => 'approved']);

        $this->artisan('members:fix-placement', ['--member' => ['X'], '--apply' => true])
            ->expectsOutputToContain('ALREADY approved/paid')->assertSuccessful();
        // WRONG keeps the approved row, RIGHT gets nothing yet; level 2 (ROOT) was already right
        $this->assertSame([[1, 'WRONG', 6000.0, 'approved'], [2, 'ROOT', 1800.0, 'pending']], $this->ic('INV-X2'));

        $this->artisan('members:fix-placement', ['--member' => ['X'], '--apply' => true, '--reissue-paid' => true])->assertSuccessful();
        $this->assertContains([1, 'RIGHT', 6000.0, 'pending'], $this->ic('INV-X2'));
    }

    public function test_ic_is_regenerated_after_a_manual_phpmyadmin_edit(): void
    {
        $this->bill('X', 'INV-X3');
        $this->bill('Y', 'INV-Y3');
        // HQ fixed members + bonds by hand: the IC is still on WRONG
        Member::whereKey($this->m['X']->id)->update(['upline_id' => $this->m['RIGHT']->id]);
        Bond::where('invoice_no', 'INV-X3')->update(['upline_member_id' => $this->m['RIGHT']->id]);

        $this->artisan('members:fix-placement', ['--member' => ['X'], '--apply' => true])->assertSuccessful();

        $this->assertSame([[1, 'RIGHT', 6000.0, 'pending'], [2, 'ROOT', 1800.0, 'pending']], $this->ic('INV-X3'));
        $this->assertSame([[1, 'X', 6000.0, 'pending'], [2, 'RIGHT', 1800.0, 'pending'], [3, 'ROOT', 600.0, 'pending']], $this->ic('INV-Y3'));
    }

    public function test_refuses_a_loop(): void
    {
        $this->artisan('members:fix-placement', ['--member' => ['X'], '--to' => 'Y', '--apply' => true])
            ->expectsOutputToContain('loop')->assertSuccessful();
        $this->assertSame($this->m['WRONG']->id, $this->m['X']->fresh()->upline_id);
    }
}
