<?php

namespace Tests\Feature;

use App\Filament\Widgets\RetailerStatsOverview;
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
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lord's board 2026-11-14 — billing margin. The old system paid every margin up to 22-10-2026
 * (never marked). Only 23-10 → 31-10-2026 of the imported rows is still owed, paid through
 * Commission Approval with the same TDS / service charge as the new system's own margins. The
 * "Pending Billing Margin" balances are closed without credit; the dealer dashboard shows ONE
 * figure, the unpaid margin; the app shows a margin to the dealer's mapped ID only.
 */
class BillMarginLegacyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $dealer;

    protected Branch $branch;

    protected Member $dealerMember;

    protected Member $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $rank = Rank::where('depth', 0)->value('id');
        $this->branch = Branch::create(['legacy_brid' => 182, 'name' => 'MARGIN DEALER', 'country' => 'IN', 'level' => 'area_dealer', 'kind' => 'dealer', 'is_active' => true, 'bill_margin' => 9000]);
        $this->dealerMember = Member::create(['member_code' => 'LJW24188', 'name' => 'Dealer', 'phone' => '9150283526', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'branch_id' => $this->branch->id]);
        $this->customer = Member::create(['member_code' => 'LJWCUST1', 'name' => 'Customer', 'phone' => '9150283527', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank]);
        $this->dealer = User::create(['name' => 'Dealer', 'email' => 'margin-dealer@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $this->branch->id, 'member_code' => 'LJW24188']);
        $this->dealer->assignRole('distributor');
        MemberWallet::create(['member_id' => $this->dealerMember->id, 'cash_balance' => 0]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function margin(string $date, string $source, float $value, string $no, string $status = 'passed'): ResellerCommission
    {
        return ResellerCommission::create([
            'bill_date' => $date, 'invoice_no' => $no, 'com_type_id' => ResellerCommission::COM_BILL_MARGIN, 'branch_id' => $this->branch->id,
            'com_value' => $value, 'net_amount' => $value, 'source' => $source, 'status' => $status,
            // old-system rows name the dealer (mapped_uid) and no buyer; new rows carry the buyer
            'mapped_uid' => $source === 'legacy' ? 'LJW24188' : null,
            'reference_member_id' => $source === 'legacy' ? null : $this->customer->id,
        ]);
    }

    public function test_only_margin_from_23_october_is_payable_and_it_is_charged_like_a_new_margin(): void
    {
        $old = $this->margin('2026-10-22', 'legacy', 150, 'L-22');       // paid in the old system
        $owed = $this->margin('2026-10-23', 'legacy', 1000, 'L-23');     // first day still owed
        $new = $this->margin('2026-11-05', 'new', 1000, 'INV-N1');

        $svc = app(CommissionApprovalService::class);
        $ids = $svc->query('BILL_MARGIN')->pluck('id')->all();
        $this->assertNotContains($old->id, $ids, 'margin up to 22-10-2026 was paid in the old system');
        $this->assertContains($owed->id, $ids, 'margin of 23-10-2026 is still owed');
        $this->assertContains($new->id, $ids);

        // the same TDS and service charge on the old-system row as on the new one
        $this->assertTrue($svc->approve($new));
        $this->assertTrue($svc->approve($owed));
        $new->refresh();
        $owed->refresh();
        $this->assertSame('paid', $owed->status);
        $this->assertEquals([(float) $new->tds, (float) $new->service_charge, (float) $new->net_amount],
            [(float) $owed->tds, (float) $owed->service_charge, (float) $owed->net_amount]);
        $this->assertEquals(round((float) $new->net_amount + (float) $owed->net_amount, 2),
            (float) MemberWallet::where('member_id', $this->dealerMember->id)->value('cash_balance'), 'both reach the dealer\'s wallet');
        $this->assertSame('passed', $old->fresh()->status);
    }

    public function test_close_legacy_marks_old_margin_paid_and_closes_the_balances_without_touching_a_wallet(): void
    {
        $old = $this->margin('2026-10-10', 'legacy', 150, 'L-10');
        $edge = $this->margin('2026-10-22', 'legacy', 200, 'L-22');
        $owed = $this->margin('2026-10-25', 'legacy', 300, 'L-25');
        $new = $this->margin('2026-11-02', 'new', 400, 'INV-N2');
        $balance = PendingBillMargin::create(['branch_id' => $this->branch->id, 'source' => 'legacy', 'amount' => 5000, 'status' => 'pending']);

        $this->artisan('billmargin:close-legacy')->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(['passed', 'pending'], [$old->fresh()->status, $balance->fresh()->status]);

        $this->artisan('billmargin:close-legacy', ['--apply' => true])->expectsOutputToContain('APPLIED: 2 row(s)')->assertSuccessful();
        $this->assertSame(['paid', 'paid', 'passed', 'passed'], [$old->fresh()->status, $edge->fresh()->status, $owed->fresh()->status, $new->fresh()->status]);
        $this->assertSame('2026-10-10', $old->fresh()->paid_on->toDateString());
        $this->assertSame(PendingBillMargin::STATUS_CLOSED, $balance->fresh()->status);
        $this->assertEquals(0.0, (float) MemberWallet::where('member_id', $this->dealerMember->id)->value('cash_balance'), 'no wallet is credited');

        // only the two unpaid rows are left to approve; running it again changes nothing
        $this->assertEqualsCanonicalizing([$owed->id, $new->id], app(CommissionApprovalService::class)->query('BILL_MARGIN')->pluck('id')->all());
        $this->artisan('billmargin:close-legacy', ['--apply' => true])->expectsOutputToContain('APPLIED: 0 row(s)')->assertSuccessful();
    }

    public function test_dealer_dashboard_shows_one_bill_margin_figure_the_unpaid_amount(): void
    {
        $this->margin('2026-10-10', 'legacy', 150, 'L-10');                 // paid in the old system — not shown
        $this->margin('2026-10-25', 'legacy', 300, 'L-25');
        $this->margin('2026-11-02', 'new', 400, 'INV-N2');
        $this->margin('2026-11-03', 'new', 999, 'INV-N3', 'paid');          // already approved — not pending
        PendingBillMargin::create(['branch_id' => $this->branch->id, 'source' => 'legacy', 'amount' => 5000, 'status' => 'pending']);

        $this->actingAs($this->dealer);
        Livewire::test(RetailerStatsOverview::class)
            ->assertSee('Bill margin')
            ->assertSee('700.00')                    // 300 + 400
            ->assertDontSee('legacy')
            ->assertDontSee('Legacy')
            ->assertDontSee('5,000.00')              // the old balance
            ->assertDontSee('9,000.00')              // the old running total
            ->assertDontSee('Bill margin earned');
    }

    /** Complaint 2026-11-13: a margin belongs to the dealer's mapped ID — the billed customer never sees it. */
    public function test_app_shows_the_margin_to_the_dealers_mapped_id_only(): void
    {
        $this->margin('2026-10-10', 'legacy', 150, 'L-10');
        $this->margin('2026-10-25', 'legacy', 300, 'L-25');
        $this->margin('2026-11-02', 'new', 400, 'INV-N2');   // billed to $this->customer

        Sanctum::actingAs($this->customer, ['*']);
        $this->assertCount(0, $this->getJson('/api/v1/member/earnings?stream=RESELLER&type=BILL_MARGIN')->assertOk()->json('data'));
        $this->getJson('/api/v1/member/earnings/summary')->assertOk()->assertJsonPath('reseller.total', 0);

        Sanctum::actingAs($this->dealerMember, ['*']);
        $rows = $this->getJson('/api/v1/member/earnings?stream=RESELLER&type=BILL_MARGIN')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['L-25', 'INV-N2'], array_column($rows, 'reference'));   // 23-10 onward only
        $this->assertSame(['passed', 'passed'], array_column($rows, 'status'));
        $this->getJson('/api/v1/member/earnings/summary')->assertOk()->assertJsonPath('reseller.total', 700);

        // an old-system row of another branch that names THIS dealer's mapped ID is theirs too …
        $other = Branch::create(['name' => 'NO LOGIN BRANCH', 'country' => 'IN', 'level' => 'area_dealer', 'kind' => 'dealer', 'is_active' => true]);
        $mapped = ResellerCommission::create(['bill_date' => '2026-10-28', 'invoice_no' => 'L-28', 'com_type_id' => 1, 'branch_id' => $other->id,
            'com_value' => 250, 'net_amount' => 250, 'source' => 'legacy', 'mapped_uid' => 'LJW24188', 'status' => 'passed']);
        $this->assertContains('L-28', array_column($this->getJson('/api/v1/member/earnings?stream=RESELLER&type=BILL_MARGIN')->json('data'), 'reference'));
        $this->assertSame($this->dealerMember->id, $mapped->payeeMember()?->id);
        // … and one with nobody to pay is never marked paid into thin air
        $orphan = ResellerCommission::create(['bill_date' => '2026-10-29', 'invoice_no' => 'L-29', 'com_type_id' => 1, 'branch_id' => $other->id,
            'com_value' => 90, 'net_amount' => 90, 'source' => 'legacy', 'mapped_uid' => 'NOBODY', 'status' => 'passed']);
        $this->assertFalse(app(CommissionApprovalService::class)->approve($orphan));
        $this->assertSame('passed', $orphan->fresh()->status);

        // the old page is gone
        $this->actingAs($this->admin);
        $this->get('/admin/pending-bill-margin')->assertNotFound();
    }
}
