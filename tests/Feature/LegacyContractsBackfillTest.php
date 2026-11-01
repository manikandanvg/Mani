<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Go-live 2026-11-01: legacy contracts (bonds) get a member_contracts row (source legacy) so
 * the Settlements screen can close / settle them; the automatic settlement engine leaves them alone.
 */
class LegacyContractsBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Plan $plan;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Legacy Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        // A plan the engine WOULD auto-settle (12-month cycle, 70 % QR) — must not touch legacy rows.
        $this->plan = Plan::create([
            'code' => 'P201', 'name' => ['en' => 'G5 Retailer Plan'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'is_contract' => true, 'is_active' => true,
            'validity_months' => 24, 'settlement_cycle_months' => 12, 'settlement_qr_pct' => 70,
        ]);
        $this->member = Member::create([
            'member_code' => 'LJW1', 'name' => 'Legacy One', 'phone' => '9000000001',
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active',
            'rank_id' => Rank::where('depth', 0)->value('id'),
        ]);
    }

    protected function bond(string $date, ?string $return, string $status = 'active'): Bond
    {
        return Bond::create([
            'member_id' => $this->member->id, 'plan_id' => $this->plan->id, 'branch_id' => $this->branch->id,
            'bond_date' => $date, 'return_date' => $return, 'value' => 50000, 'invoice_no' => 'INV-' . random_int(1000, 9999),
            'status' => $status,
        ]);
    }

    public function test_backfill_creates_one_legacy_contract_per_bond_with_the_right_state(): void
    {
        $running = $this->bond('2026-06-01', '2028-06-01');                 // end in the future → active
        $expired = $this->bond('2024-01-10', '2025-01-10');                 // end passed → matured
        $badDate = $this->bond('2025-07-04', '2025-07-04');                 // legacy return = bond date → plan validity (24 m) → active
        $closed = $this->bond('2023-03-01', '2024-03-01', 'closed');        // closed in legacy → closed

        $this->artisan('contracts:backfill-legacy --dry-run')->assertSuccessful();
        $this->assertSame(0, MemberContract::count());

        $this->artisan('contracts:backfill-legacy')->assertSuccessful();
        $this->assertSame(4, MemberContract::count());

        $c = MemberContract::where('bond_id', $running->id)->firstOrFail();
        $this->assertSame('LEG-' . $running->id, $c->contract_no);
        $this->assertSame(MemberContract::SOURCE_LEGACY, $c->source);
        $this->assertSame('active', $c->status);
        $this->assertSame('2028-06-01', $c->end_date->toDateString());
        $this->assertEquals(50000.0, (float) $c->amount);
        $this->assertSame($this->member->id, $c->member_id);
        $this->assertSame($this->branch->id, $c->branch_id);

        $c = MemberContract::where('bond_id', $expired->id)->firstOrFail();
        $this->assertSame('matured', $c->status);
        $this->assertTrue($c->isExpired());
        $this->assertNull($c->settled_on);

        $c = MemberContract::where('bond_id', $badDate->id)->firstOrFail();
        $this->assertSame('2027-07-04', $c->end_date->toDateString(), 'return_date = bond_date is legacy noise → bond date + plan validity');
        $this->assertSame('active', $c->status);

        $c = MemberContract::where('bond_id', $closed->id)->firstOrFail();
        $this->assertSame('closed', $c->status);
        $this->assertSame('2024-03-01', substr((string) $c->settled_on, 0, 10));

        // idempotent
        $this->artisan('contracts:backfill-legacy')->assertSuccessful();
        $this->assertSame(4, MemberContract::count());
    }

    public function test_settlement_engine_never_auto_settles_legacy_contracts(): void
    {
        $this->bond(now()->subMonthsNoOverflow(13)->toDateString(), now()->addMonthsNoOverflow(11)->toDateString());
        $this->artisan('contracts:backfill-legacy')->assertSuccessful();
        $legacy = MemberContract::firstOrFail();
        $this->assertSame('active', $legacy->status);

        // Same plan, same age, but a contract the new system wrote itself → settled as designed.
        $own = $this->bond(now()->subMonthsNoOverflow(13)->toDateString(), null);
        $system = MemberContract::create([
            'contract_no' => 'LJC-000001', 'bond_id' => $own->id, 'member_id' => $this->member->id,
            'plan_id' => $this->plan->id, 'branch_id' => $this->branch->id, 'invoice_no' => $own->invoice_no,
            'amount' => 50000, 'start_date' => $own->bond_date, 'end_date' => now()->addMonthsNoOverflow(11)->toDateString(),
            'content' => '', 'status' => 'active',
        ]);

        $n = app(SettlementService::class)->run();

        $this->assertSame(1, $n);
        $this->assertNull($legacy->fresh()->settled_on, 'legacy contract untouched');
        $this->assertSame(0, RedeemableQr::where('bond_id', $legacy->bond_id)->count());
        $this->assertNotNull($system->fresh()->settled_on);
        $this->assertSame(1, RedeemableQr::where('bond_id', $own->id)->count());
    }
}
