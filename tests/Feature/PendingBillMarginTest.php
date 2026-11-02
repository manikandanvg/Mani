<?php

namespace Tests\Feature;

use App\Filament\Pages\CommissionApproval;
use App\Filament\Pages\PendingBillMargin as PendingBillMarginPage;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\PendingBillMargin;
use App\Models\Rank;
use App\Models\ResellerCommission;
use App\Models\User;
use App\Services\CommissionApprovalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User 2026-11-02: a dealer's legacy accrued billing margin is never pushed into a wallet.
 * It waits on Commissions → Pending Billing Margin and Head Office sends it to the dealer's
 * cash wallet by hand (Pending Cash QR pattern). Legacy dealer-margin HISTORY rows
 * (source legacy) never show in the Commission Approval queue.
 */
class PendingBillMarginTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Branch $branch;

    protected Member $member;

    protected PendingBillMargin $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Services\Activity\ActivityLogger::$enabled = true;
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();

        $this->branch = Branch::create(['legacy_brid' => 182, 'name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'phone' => '9150283526', 'incharge' => 'MURUGAN', 'country' => 'IN', 'level' => 'area_dealer', 'kind' => 'dealer', 'is_active' => true, 'bill_margin' => 500]);
        $this->member = Member::create(['member_code' => 'LJW24188', 'name' => 'Murugan V', 'phone' => '9150283526', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id'), 'branch_id' => $this->branch->id]);
        $dealer = User::create(['name' => 'Murugan', 'email' => 'murugan@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $this->branch->id, 'member_code' => 'LJW24188']);
        $dealer->assignRole('distributor');
        MemberWallet::create(['member_id' => $this->member->id, 'cash_balance' => 100]);
        $this->row = PendingBillMargin::create(['branch_id' => $this->branch->id, 'source' => 'legacy', 'amount' => 500, 'status' => 'pending']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_hq_sends_the_legacy_margin_to_the_dealers_cash_wallet_by_hand(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/pending-bill-margin')->assertOk();

        Livewire::test(PendingBillMarginPage::class)
            ->assertCanSeeTableRecords([$this->row])
            ->assertTableActionVisible('send', $this->row)
            ->callTableAction('send', $this->row)
            ->assertNotified();

        $this->assertEquals(600, (float) MemberWallet::where('member_id', $this->member->id)->value('cash_balance'), '100 + the ₹500 legacy margin, no deduction');
        $row = $this->row->fresh();
        $this->assertSame('sent', $row->status);
        $this->assertSame($this->member->id, $row->member_id);
        $this->assertSame($this->admin->id, $row->decided_by);
        $this->assertSame(1, ActivityLog::where('action', 'pending_bill_margin_sent')->count());

        // already decided → refused, wallet unchanged
        Livewire::test(PendingBillMarginPage::class)->assertTableActionHidden('send', $row);
        try {
            app(\App\Services\Commission\PendingBillMarginService::class)->send($row, $this->admin->id);
            $this->fail('a sent margin must not be sent twice');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertEquals(600, (float) MemberWallet::where('member_id', $this->member->id)->value('cash_balance'));
    }

    public function test_bulk_send_skips_a_branch_without_a_member_account_and_reject_keeps_the_wallet(): void
    {
        $this->actingAs($this->admin);
        $noLogin = Branch::create(['legacy_brid' => 183, 'name' => 'LORD JEWELLER', 'city' => 'SIVAKASI', 'country' => 'IN', 'level' => 'wholesaler', 'kind' => 'dealer', 'is_active' => true]);
        $orphan = PendingBillMargin::create(['branch_id' => $noLogin->id, 'source' => 'legacy', 'amount' => 300, 'status' => 'pending']);

        Livewire::test(PendingBillMarginPage::class)
            ->callTableBulkAction('bulkSend', [$this->row, $orphan]);

        $this->assertSame('sent', $this->row->fresh()->status);
        $this->assertSame('pending', $orphan->fresh()->status, 'no distributor login / member account → skipped, still pending');
        $this->assertEquals(600, (float) MemberWallet::where('member_id', $this->member->id)->value('cash_balance'));

        Livewire::test(PendingBillMarginPage::class)
            ->callTableAction('reject', $orphan, ['remarks' => 'branch closed in 2024']);
        $orphan->refresh();
        $this->assertSame('rejected', $orphan->status);
        $this->assertSame('branch closed in 2024', $orphan->remarks);
        $this->assertSame(0, MemberWallet::where('cash_balance', '>', 600)->count());
    }

    public function test_dealers_cannot_open_the_page(): void
    {
        $this->actingAs(User::where('email', 'murugan@test.local')->firstOrFail());
        $this->get('/admin/pending-bill-margin')->assertForbidden();
    }

    public function test_legacy_margin_history_rows_never_enter_the_approval_queue(): void
    {
        $this->actingAs($this->admin);
        $mk = fn (string $source, string $no) => ResellerCommission::create([
            'bill_date' => '2026-10-10', 'invoice_no' => $no, 'com_type_id' => ResellerCommission::COM_BILL_MARGIN, 'branch_id' => $this->branch->id,
            'com_value' => 150, 'reference_member_id' => $this->member->id, 'status' => 'passed', 'source' => $source,
        ]);
        $legacy = $mk('legacy', 'L-1');
        $new = $mk('new', 'N-1');

        $ids = app(CommissionApprovalService::class)->query('BILL_MARGIN')->pluck('id')->all();
        $this->assertContains($new->id, $ids, 'a margin earned in the new system waits for approval');
        $this->assertNotContains($legacy->id, $ids, 'legacy history was settled in legacy — never approvable again');
    }
}
