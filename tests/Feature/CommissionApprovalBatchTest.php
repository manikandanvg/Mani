<?php

namespace Tests\Feature;

use App\Filament\Pages\CommissionApproval;
use App\Jobs\ApproveCommissionsJob;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\User;
use App\Services\CommissionApprovalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User 2026-11-03: approving the 1 Nov CBC coupons (11,624 rows, 8,843 members) from the
 * Commission Approval page died with the web-server timeout after 88 rows. Big approvals now
 * run on the queue in self-continuing chunks; small ones stay inline; the shell command does
 * the same with no timeout at all.
 */
class CommissionApprovalBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function seedCoupons(int $members, int $perMember): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $plan = Plan::create(['code' => 'P209', 'name' => ['en' => 'G11'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 1500, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_contract' => true, 'is_active' => true, 'validity_months' => 11]);
        $rank = Rank::where('depth', 0)->value('id');
        $n = 0;
        for ($i = 1; $i <= $members; $i++) {
            $m = Member::create(['member_code' => 'LJW9' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'name' => "M{$i}", 'phone' => '90000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
            $bond = Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-10-01', 'value' => 1500, 'invoice_no' => (string) $i, 'status' => 'active']);
            for ($j = 0; $j < $perMember; $j++) {
                CbcEntry::create(['member_id' => $m->id, 'bond_id' => $bond->id, 'code' => 'C' . str_pad((string) (++$n), 6, '0', STR_PAD_LEFT), 'worth' => 100, 'cbc_date' => '2026-11-01', 'status' => 'pending']);
            }
        }
    }

    public function test_a_small_approval_runs_inline_and_a_big_one_goes_to_the_queue_in_chunks(): void
    {
        $this->seedCoupons(members: 4, perMember: 120);   // 480 coupons; 360 left after the first member = above INLINE_LIMIT
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        $svc = app(CommissionApprovalService::class);
        $ids = fn () => $svc->query('CBC', '2026-11-01', '2026-11-01')->reorder()
            ->selectRaw('MIN(id) AS id, member_id, COUNT(*) AS entries_count, SUM(worth) AS total_amount, MIN(cbc_date) AS first_earned, MAX(cbc_date) AS last_earned')
            ->groupBy('member_id')->get()->pluck('id')->all();

        // one member (120 coupons) → inline, paid at once, wallet credited 50 % cash + coupon remainder
        $first = Member::where('member_code', 'LJW9001')->firstOrFail();
        Livewire::test(CommissionApproval::class)
            ->fillForm(['type' => 'CBC', 'from' => '2026-11-01', 'to' => '2026-11-01'])
            ->callTableBulkAction('approve', [CbcEntry::where('member_id', $first->id)->min('id')])
            ->assertNotified();
        $this->assertSame(120, CbcEntry::where('member_id', $first->id)->where('status', 'paid')->count());
        $this->assertEquals(6000.0, (float) MemberWallet::find($first->id)->cash_balance);
        $this->assertSame(360, CbcEntry::where("status", "pending")->count());

        // the remaining three members (360 coupons) selected together → queued, nothing paid yet
        Queue::fake();
        Livewire::test(CommissionApproval::class)
            ->fillForm(['type' => 'CBC', 'from' => '2026-11-01', 'to' => '2026-11-01'])
            ->callTableBulkAction('approve', $ids())
            ->assertNotified();
        Queue::assertPushed(ApproveCommissionsJob::class, fn ($job) => $job->type === 'CBC' && count($job->beneficiaryIds) === 3);
        $this->assertSame(360, CbcEntry::where("status", "pending")->count());

        // the job works for its time budget, then re-dispatches itself until everything is paid
        ApproveCommissionsJob::$workSeconds = 0.0;
        $job = new ApproveCommissionsJob('CBC', '2026-11-01', '2026-11-01', Member::whereIn('member_code', ['LJW9002', 'LJW9003', 'LJW9004'])->pluck('id')->all(), null);
        $job->handle($svc);
        $this->assertSame(359, CbcEntry::where('status', 'pending')->count(), 'budget 0 → exactly one row per run, never a stall');
        Queue::assertPushed(ApproveCommissionsJob::class, fn ($j) => $j->round === 2);
        ApproveCommissionsJob::$workSeconds = 40;
        (new ApproveCommissionsJob('CBC', '2026-11-01', '2026-11-01', $job->beneficiaryIds, null, 2))->handle($svc);
        $this->assertSame(0, CbcEntry::where('status', 'pending')->count());
        $this->assertGreaterThanOrEqual(2, \Illuminate\Support\Facades\DB::table('activity_logs')->where('action', 'commissions_approved')->count(), 'each batch is on the Activity Log');
    }

    public function test_the_shell_command_approves_a_whole_window_without_a_timeout(): void
    {
        $this->seedCoupons(members: 3, perMember: 2);
        $this->artisan('commission:approve', ['--type' => 'CBC', '--from' => '2026-11-01', '--to' => '2026-11-01', '--dry-run' => true])
            ->expectsOutputToContain('6 un-paid rows')->assertSuccessful();
        $this->assertSame(6, CbcEntry::where('status', 'pending')->count());

        $this->artisan('commission:approve', ['--type' => 'CBC', '--from' => '2026-11-01', '--to' => '2026-11-01', '--no-interaction' => true])
            ->expectsOutputToContain('Approved 6 rows')->assertSuccessful();
        $this->assertSame(0, CbcEntry::where('status', 'pending')->count());
        $this->assertSame(6, CbcEntry::where('status', 'paid')->whereDate('paid_on', now()->toDateString())->count());
        // running again pays nothing twice
        $this->artisan('commission:approve', ['--type' => 'CBC', '--from' => '2026-11-01', '--to' => '2026-11-01', '--no-interaction' => true])
            ->expectsOutputToContain('0 un-paid rows')->assertSuccessful();
    }
}
