<?php

namespace Tests\Feature;

use App\Filament\Resources\BranchPayoutRequestResource\Pages\ListBranchPayoutRequests;
use App\Filament\Resources\WalletWithdrawalResource\Pages\ListWalletWithdrawals;
use App\Models\Branch;
use App\Models\BranchPayoutRequest;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Rank;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-03 — a branch never fronts the cash for a wallet withdrawal:
 * pending → requested (branch bundles to HQ) → admin_paid (HQ pays the bundle) → disbursed.
 * Only HQ cancels; a branch can only request and disburse. Old branch-marked disbursals
 * can be undone back to pending without touching any balance.
 */
class BranchPayoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $dealer;

    protected Member $dealerMember;

    protected Member $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = Branch::create(['name' => 'Rajapalayam', 'city' => 'Rajapalayam', 'phone' => '9100000001', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $rank = Rank::where('depth', 0)->value('id');
        $this->dealerMember = Member::create(['member_code' => 'LJWD1', 'name' => 'Dealer', 'phone' => '9100000001', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank,
            'branch_id' => $this->branch->id, 'bank_name' => 'SBI', 'bank_acno' => '30012345678', 'ifsc' => 'SBIN0000123']);
        $this->dealer = User::create(['name' => 'Dealer', 'email' => 'dealer@test.local', 'password' => bcrypt('secret123'), 'status' => 'active',
            'branch_id' => $this->branch->id, 'member_code' => 'LJWD1']);
        $this->dealer->assignRole('distributor');

        $this->customer = Member::create(['member_code' => 'LJWC1', 'name' => 'Customer', 'phone' => '9100000002', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'branch_id' => $this->branch->id]);
        MemberWallet::create(['member_id' => $this->customer->id, 'cash_balance' => 10000, 'earning_total' => 10000]);
    }

    protected function pendingRow(float $amount): WalletWithdrawal
    {
        MemberWallet::where('member_id', $this->customer->id)->decrement('cash_balance', $amount);

        return WalletWithdrawal::create(['member_id' => $this->customer->id, 'branch_id' => $this->branch->id, 'wallet' => 'member_cash', 'amount' => $amount, 'status' => 'pending']);
    }

    public function test_the_branch_requests_hq_pays_and_only_then_the_branch_disburses(): void
    {
        $svc = app(WalletWithdrawalService::class);
        $a = $this->pendingRow(3000);
        $b = $this->pendingRow(2000);

        // the branch cannot disburse a pending row — HQ has not funded it
        try {
            $svc->disburse($a, 'cash', $this->dealer->id);
            $this->fail('A branch must not disburse before HQ paid.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not paid', $e->getMessage());
        }

        // step 2 — the branch bundles both rows (bulk action on its own Wallet Withdrawals list)
        $this->actingAs($this->dealer);
        Livewire::test(ListWalletWithdrawals::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertTableActionHidden('disburse', $a)
            ->assertTableActionHidden('cancel', $a)
            ->callTableBulkAction('request_payout', [$a, $b], ['note' => 'please fund'])
            ->assertNotified();
        $req = BranchPayoutRequest::firstOrFail();
        $this->assertSame(['requested', 'requested'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame($req->id, $a->fresh()->payout_request_id);
        $this->assertSame(2, $req->rows_count);
        $this->assertEquals(5000.0, (float) $req->amount);
        $this->assertSame('requested', $req->status);

        // a requested row cannot be bundled twice
        try {
            $svc->requestPayout($this->dealer, [$a->id]);
            $this->fail('Requested rows must not be re-requested.');
        } catch (\RuntimeException) {
        }

        // step 3 — HQ sees the bundle with the dealer's bank and pays it with a reference
        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($admin);
        Livewire::test(ListBranchPayoutRequests::class)
            ->assertCanSeeTableRecords([$req])
            ->assertSee('30012345678')->assertSee('SBIN0000123')->assertSee('Rajapalayam')
            ->callTableAction('pay', $req, ['payment_ref' => 'UTR123', 'note' => 'NEFT done'])
            ->assertNotified();
        $req->refresh();
        $this->assertSame('paid', $req->status);
        $this->assertSame('UTR123', $req->payment_ref);
        $this->assertSame($admin->id, $req->paid_by);
        $this->assertSame(['admin_paid', 'admin_paid'], [$a->fresh()->status, $b->fresh()->status]);

        // step 4 — Disburse now shows for the branch; Cancel never does
        $this->actingAs($this->dealer);
        Livewire::test(ListWalletWithdrawals::class)
            ->assertTableActionVisible('disburse', $a->fresh())
            ->assertTableActionHidden('cancel', $a->fresh())
            ->callTableAction('disburse', $a->fresh(), ['mode' => 'cash'])
            ->assertNotified();
        $this->assertSame('disbursed', $a->fresh()->status);
        $this->assertSame('cash', $a->fresh()->disbursal_mode);

        // HQ may cancel the other (already paid) row — the member's wallet gets it back
        $this->actingAs($admin);
        Livewire::test(ListWalletWithdrawals::class)
            ->assertTableActionVisible('cancel', $b->fresh())
            ->callTableAction('cancel', $b->fresh(), ['note' => 'customer withdrew the request'])
            ->assertNotified();
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertEquals(7000.0, (float) MemberWallet::find($this->customer->id)->cash_balance);
        $this->assertSame(1, $req->fresh()->rows_count);
        $this->assertEquals(3000.0, (float) $req->fresh()->amount);
    }

    public function test_hq_may_cancel_an_unpaid_bundle_and_disburse_a_pending_row_directly(): void
    {
        $svc = app(WalletWithdrawalService::class);
        $a = $this->pendingRow(1500);
        $req = $svc->requestPayout($this->dealer, [$a->id]);

        $svc->cancelRequest($req, null, 'wrong amount');
        $this->assertSame('cancelled', $req->fresh()->status);
        $this->assertSame('pending', $a->fresh()->status);
        $this->assertNull($a->fresh()->payout_request_id);

        // HQ's own counter: a pending row can be disbursed by HQ without a bundle
        $svc->disburse($a->fresh(), 'gold', null, null, byHq: true);
        $this->assertSame('disbursed', $a->fresh()->status);

        // a paid bundle cannot be cancelled as a bundle
        $b = $this->pendingRow(500);
        $req2 = $svc->requestPayout($this->dealer, [$b->id]);
        $svc->payRequest($req2, null, 'UTR9');
        try {
            $svc->cancelRequest($req2);
            $this->fail('A paid bundle must not be cancelled.');
        } catch (\RuntimeException) {
        }
    }

    public function test_undo_command_returns_branch_marked_disbursals_to_pending_without_touching_balances(): void
    {
        $svc = app(WalletWithdrawalService::class);
        $a = $this->pendingRow(1000);
        $b = $this->pendingRow(2000);
        WalletWithdrawal::whereIn('id', [$a->id, $b->id])->update(['status' => 'disbursed', 'disbursal_mode' => 'cash', 'disbursed_at' => now(), 'disbursed_by' => $this->dealer->id]);
        $before = (float) MemberWallet::find($this->customer->id)->cash_balance;

        $this->artisan('withdrawals:undo-disbursals', ['--ids' => "{$a->id},{$b->id},999", '--dry-run' => true])
            ->expectsOutputToContain('2 row(s) would go back to pending')->assertSuccessful();
        $this->assertSame('disbursed', $a->fresh()->status);

        $this->artisan('withdrawals:undo-disbursals', ['--ids' => "{$a->id},{$b->id}", '--no-interaction' => true])
            ->expectsOutputToContain('2 row(s) are pending again')->assertSuccessful();
        $this->assertSame(['pending', 'pending'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertNull($a->fresh()->disbursed_at);
        $this->assertEquals($before, (float) MemberWallet::find($this->customer->id)->cash_balance);

        // and they enter the new chain normally
        $req = $svc->requestPayout($this->dealer, [$a->id, $b->id]);
        $this->assertEquals(3000.0, (float) $req->amount);
    }

    public function test_withdraw_my_wallet_lists_the_dealers_requests_in_a_searchable_table(): void
    {
        $svc = app(WalletWithdrawalService::class);
        MemberWallet::create(['member_id' => $this->dealerMember->id, 'cash_balance' => 5000, 'earning_total' => 5000]);
        $this->branch->update(['digi_cash_balance' => 900]);
        $own = $svc->requestFromPanel($this->dealer, 'member_cash', 1200, 'own cash');
        $digi = $svc->requestFromPanel($this->dealer, 'branch_digi', 300, 'digi pot');
        $customer = $this->pendingRow(700);   // a customer's scan is NOT the dealer's own request

        $this->actingAs($this->dealer);
        $this->get('/admin/withdraw-my-wallet')->assertOk();
        Livewire::test(\App\Filament\Pages\WithdrawMyWallet::class)
            ->assertCanSeeTableRecords([$own, $digi])
            ->assertCanNotSeeTableRecords([$customer])
            ->searchTable('digi pot')->assertCanSeeTableRecords([$digi])->assertCanNotSeeTableRecords([$own])
            ->searchTable('')
            ->filterTable('wallet', 'member_cash')->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$digi])
            ->resetTableFilters()
            ->sortTable('amount')->assertCanSeeTableRecords([$digi, $own], inOrder: true)
            ->sortTable('amount', 'desc')->assertCanSeeTableRecords([$own, $digi], inOrder: true);
        $this->assertEquals(3800.0, (float) MemberWallet::find($this->dealerMember->id)->cash_balance);
        $this->assertEquals(600.0, (float) $this->branch->fresh()->digi_cash_balance);
    }
}
