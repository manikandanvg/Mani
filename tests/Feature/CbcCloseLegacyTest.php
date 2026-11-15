<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User 2026-11-15: the old system paid the 1 Oct 2026 CBC but never marked it. `cbc:close-legacy`
 * marks those imported coupons paid — crediting nothing — and leaves the new system's own
 * October-dated coupons (Sub Dealer schedule of 5 Nov) pending.
 */
class CbcCloseLegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_system_october_coupons_are_marked_paid_without_any_wallet_credit(): void
    {
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true]);
        $plan = Plan::create(['code' => 'PX1', 'name' => ['en' => 'Plan'], 'plan_type' => 2, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);
        $member = Member::create(['member_code' => 'CB1', 'name' => 'Cb', 'phone' => '9000000011', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::value('id')]);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-01-01', 'value' => 100000, 'invoice_no' => 'I1', 'status' => 'active']);
        $coupon = function (string $code, string $date, string $createdAt, array $extra = []) use ($bond, $member) {
            $c = CbcEntry::create(array_merge(['bond_id' => $bond->id, 'member_id' => $member->id, 'cbc_date' => $date, 'code' => $code, 'worth' => 6000, 'status' => 'pending'], $extra));
            \DB::table('cbc_entries')->where('id', $c->id)->update(['created_at' => $createdAt]);

            return $c;
        };
        $old = $coupon('OLD1', '2026-10-01', '2026-10-01 00:00:00');                 // imported, unpaid in the old tables
        $ours = $coupon('NEW1', '2026-10-01', '2026-11-05 17:27:00');                // new system's own (Sub Dealer schedule)
        $nov = $coupon('NOV1', '2026-11-01', '2026-11-01 00:00:00');                 // November is the new system's month

        // dry run writes nothing
        $this->artisan('cbc:close-legacy')->assertSuccessful();
        $this->assertSame('pending', $old->fresh()->status);

        $this->artisan('cbc:close-legacy', ['--apply' => true, '--paid-on' => '2026-10-07'])->assertSuccessful();

        $old->refresh();
        $this->assertSame('paid', $old->status);
        $this->assertSame('2026-10-07', \Illuminate\Support\Carbon::parse($old->paid_on)->toDateString());
        $this->assertEquals(0, (float) $old->cash_amount);                            // no split written
        $this->assertSame('pending', $ours->fresh()->status);
        $this->assertSame('pending', $nov->fresh()->status);
        $this->assertEquals(0, (float) (MemberWallet::where('member_id', $member->id)->value('cash_balance') ?? 0));

        // safe to run again; a new-system month is refused
        $this->artisan('cbc:close-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertSame('2026-10-07', \Illuminate\Support\Carbon::parse($old->fresh()->paid_on)->toDateString());
        $this->artisan('cbc:close-legacy', ['--date' => '2026-11-01', '--apply' => true])->assertFailed();
        $this->assertSame('pending', $nov->fresh()->status);
    }
}
