<?php

namespace Tests\Feature;

use App\Filament\Resources\MemoResource;
use App\Filament\Widgets\DealerPayoutFlow;
use App\Models\Branch;
use App\Models\BranchPayoutRequest;
use App\Models\Device;
use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\Memo;
use App\Models\Rank;
use App\Models\User;
use App\Models\WalletWithdrawal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * User 2026-11-15:
 *  - distributor dashboard: five L-BOX payout cards (collected → requested → approved → pending
 *    disbursal → disbursed), amount + transaction count, own branch only;
 *  - Community → Memo: send to everyone, dealers only, chosen ranks or particular distributors.
 */
class DealerPayoutCardsAndMemoAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function member(string $code, int $depth = 0): Member
    {
        $rank = Rank::firstOrCreate(['depth' => $depth], ['code' => 'R' . $depth, 'name' => 'Rank ' . $depth, 'is_active' => true]);
        $m = Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank->id]);
        DeviceToken::create(['owner_type' => Member::class, 'owner_id' => $m->id, 'token' => 'tok-' . $code, 'platform' => 'android', 'provider' => 'fcm']);

        return $m;
    }

    protected function dealer(Branch $branch, string $email, ?string $memberCode = null): User
    {
        Role::findOrCreate('distributor', 'web');
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id, 'member_code' => $memberCode]);
        $u->assignRole('distributor');

        return $u;
    }

    public function test_dealer_dashboard_shows_the_five_payout_cards_for_its_own_branch(): void
    {
        $mine = Branch::create(['name' => 'Mine', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $other = Branch::create(['name' => 'Other', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $user = $this->dealer($mine, 'mine@test.local');
        $payer = $this->member('PAY1');
        $box = Device::create(['name' => 'Box', 'serial_no' => 'B-1', 'board_type' => 'qr', 'branch_id' => $mine->id, 'status' => 'active']);
        $paid = BranchPayoutRequest::create(['branch_id' => $mine->id, 'rows_count' => 2, 'amount' => 700, 'status' => 'paid', 'paid_at' => now()]);
        $open = BranchPayoutRequest::create(['branch_id' => $mine->id, 'rows_count' => 1, 'amount' => 200, 'status' => 'requested']);

        $row = fn (Branch $b, float $amount, string $status, ?int $device = null, ?int $request = null) => WalletWithdrawal::create([
            'member_id' => $payer->id, 'branch_id' => $b->id, 'device_id' => $device, 'payout_request_id' => $request,
            'wallet' => 'cash', 'amount' => $amount, 'status' => $status,
        ]);
        $row($mine, 100, 'pending', $box->id);                       // scanned, not yet requested
        $row($mine, 200, 'requested', $box->id, $open->id);          // awaiting Head Office
        $row($mine, 300, 'admin_paid', $box->id, $paid->id);         // approved, to hand over
        $row($mine, 400, 'disbursed', $box->id, $paid->id);          // handed over
        $row($mine, 50, 'cancelled', $box->id);                      // never counted
        $row($mine, 60, 'disbursed');                                // dealer's own panel withdrawal: not an L-BOX collection
        $row($other, 9999, 'disbursed', $box->id);                   // another branch

        $this->actingAs($user);
        $this->assertTrue(DealerPayoutFlow::canView());
        $w = new DealerPayoutFlow;
        $m = new \ReflectionMethod($w, 'getStats');
        $m->setAccessible(true);
        $stats = collect($m->invoke($w))->mapWithKeys(fn ($s) => [(string) $s->getLabel() => [$s->getValue(), (string) $s->getDescription()]]);

        $this->assertStringContainsString('1,000.00', $stats['LBOX Collected'][0]);      // 100 + 200 + 300 + 400
        $this->assertStringContainsString('4 transactions', $stats['LBOX Collected'][1]);
        $this->assertStringContainsString('200.00', $stats['Admin Request'][0]);
        $this->assertStringContainsString('1 transaction ', $stats['Admin Request'][1]);
        $this->assertStringContainsString('700.00', $stats['Admin Approved'][0]);         // 300 + 400 funded by HQ
        $this->assertStringContainsString('2 transactions', $stats['Admin Approved'][1]);
        $this->assertStringContainsString('300.00', $stats['Pending Disbursed'][0]);
        $this->assertStringContainsString('460.00', $stats['Disbursed'][0]);              // 400 + 60, this branch only
        $this->assertStringContainsString('2 transactions', $stats['Disbursed'][1]);

        // Head Office does not see the dealer cards
        $this->actingAs(User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail());
        $this->assertFalse(DealerPayoutFlow::canView());
    }

    public function test_memo_goes_only_to_the_chosen_audience(): void
    {
        $branch = Branch::create(['name' => 'Dealer Branch', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $dealerMember = $this->member('DEAL1', 2);
        $plain = $this->member('PLAIN1', 0);
        $ranked = $this->member('RANK5', 5);
        $this->dealer($branch, 'dealer@test.local', 'DEAL1');
        $inbox = fn (Member $m) => \DB::table('notifications')->where('notifiable_type', Member::class)->where('notifiable_id', $m->id)->count();
        $send = fn (array $attrs) => MemoResource::broadcast(Memo::create(array_merge(['title' => 'T', 'body' => 'B'], $attrs)));

        // dealers only = the distributor behind an active branch login
        $this->assertSame(1, $send(['audience' => 'dealers']));
        $this->assertSame([1, 0, 0], [$inbox($dealerMember), $inbox($plain), $inbox($ranked)]);

        // by rank — the Zoom-style picker
        $this->assertSame(1, $send(['audience' => 'ranks', 'audience_ranks' => [5]]));
        $this->assertSame([1, 0, 1], [$inbox($dealerMember), $inbox($plain), $inbox($ranked)]);

        // particular distributors
        $memo = Memo::create(['title' => 'T', 'body' => 'B', 'audience' => 'members', 'audience_members' => [$plain->id]]);
        $this->assertSame(1, MemoResource::broadcast($memo));
        $this->assertSame([1, 1, 1], [$inbox($dealerMember), $inbox($plain), $inbox($ranked)]);
        $this->assertSame('1 distributor(s)', $memo->audienceLabel());
        $this->assertSame(1, (int) $memo->fresh()->sent_count);

        // everyone, as before
        $this->assertGreaterThanOrEqual(3, $send(['audience' => 'all']));
        $this->assertSame([2, 2, 2], [$inbox($dealerMember), $inbox($plain), $inbox($ranked)]);
    }
}
