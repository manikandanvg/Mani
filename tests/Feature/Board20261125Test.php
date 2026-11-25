<?php

namespace Tests\Feature;

use App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Plan;
use App\Models\RdEntry;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\Support\SupportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-25 (second list): Order Requests shows the staff contact number, the branch
 * in-charge and the MAP ID; renewals of 2–4 Nov that produced no Sales Incentive are backfilled;
 * the Support Chat list no longer queries per row.
 */
class Board20261125Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@lordicl.com')->firstOrFail();
    }

    public function test_order_requests_show_staff_contact_branch_incharge_and_map_id(): void
    {
        $branch = Branch::create(['name' => 'Dealer OR', 'country' => 'IN', 'is_active' => true, 'phone' => '9000000001', 'incharge' => 'RAVI K / LJWMAP77']);
        $staff = User::create(['name' => 'Ravi', 'email' => 'ravi@test.local', 'password' => 'secret123', 'status' => 'active',
            'branch_id' => $branch->id, 'phone' => '9111111111', 'member_code' => 'LJWMAP77']);
        $staff->assignRole('distributor');
        $bare = Branch::create(['name' => 'Dealer no login', 'country' => 'IN', 'is_active' => true, 'phone' => '9222222222']);
        $mk = fn (Branch $b, string $no) => BranchOrderRequest::create(['request_no' => $no, 'source' => 'order_form', 'branch_id' => $b->id,
            'no_of_items' => 0, 'cross_total' => 0, 'gst_total' => 0, 'grand_total' => 0, 'payment_type' => 'cash', 'status' => 'pending']);
        $order = $mk($branch, 'ORD-T0001');
        $other = $mk($bare, 'ORD-T0002');

        $this->actingAs($this->admin());
        $this->get('/admin/login');
        Livewire::test(ListBranchOrders::class)
            ->assertTableColumnExists('branch.incharge')
            ->assertTableColumnExists('map_id')
            // the STAFF number (the branch login's), not the branch landline
            ->assertTableColumnStateSet('branch.phone', '9111111111', $order)
            ->assertTableColumnStateSet('branch.incharge', 'RAVI K / LJWMAP77', $order)
            ->assertTableColumnStateSet('map_id', 'LJWMAP77', $order)
            // a branch without a login falls back to the branch phone
            ->assertTableColumnStateSet('branch.phone', '9222222222', $other)
            ->searchTable('LJWMAP77')
            ->assertCanSeeTableRecords([$order])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('9111111111')
            ->assertCanSeeTableRecords([$order])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_renewals_that_produced_no_sales_incentive_are_backfilled_once(): void
    {
        $plan = Plan::create(['code' => 'GSB', 'name' => ['en' => 'Gold Saving'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 0,
            'allocation_bv' => 100, 'validity_months' => 11, 'ic_schedule' => [10, 3], 'billing_margin' => 2, 'is_active' => true]);
        $branch = Branch::create(['name' => 'Dealer IC', 'country' => 'IN', 'is_active' => true]);
        $upline = Member::firstOrFail();
        Bond::create(['member_id' => $upline->id, 'plan_id' => $plan->id, 'bond_date' => '2026-01-01', 'value' => 500000, 'status' => 'active']);
        $saver = Member::create(['member_code' => 'LJWSAVER1', 'name' => 'Saver', 'phone' => '9333333333', 'upline_id' => $upline->id,
            'referrer_id' => $upline->id, 'branch_id' => $branch->id, 'joined_on' => '2026-10-01', 'status' => 'active', 'rank_id' => $upline->rank_id]);
        $bond = Bond::create(['member_id' => $saver->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-10-01',
            'value' => 1500, 'epin_value' => 1500, 'lvlcom_count' => 11, 'status' => 'active']);
        // a renewal entered before the 4 Nov rule: the entry exists, no incentive line
        $entry = RdEntry::create(['bond_id' => $bond->id, 'member_id' => $saver->id, 'paid_on' => '2026-11-02', 'value' => 1500, 'due_count' => 2, 'branch_id' => $branch->id]);
        $entry->forceFill(['created_at' => '2026-11-02 10:00:00'])->save();

        $this->artisan('ic:backfill-renewals')->assertSuccessful();
        $this->assertSame(0, CommissionLedger::where('invoice_no', 'RD-' . $entry->id)->count(), 'a dry run writes nothing');

        $this->artisan('ic:backfill-renewals', ['--apply' => true])->assertSuccessful();
        $line = CommissionLedger::where('invoice_no', 'RD-' . $entry->id)->where('member_id', $upline->id)->firstOrFail();
        $this->assertSame('pending', $line->status, 'paid only through Commission Approval');
        $this->assertSame('IC', $line->type);
        $this->assertEqualsWithDelta(150, (float) $line->amount, 0.01);   // 10 % of the ₹1,500 bond value
        $this->assertSame('2026-11-02', \Illuminate\Support\Carbon::parse($line->earned_on)->toDateString());
        $count = CommissionLedger::where('invoice_no', 'RD-' . $entry->id)->count();

        $this->artisan('ic:backfill-renewals', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count, CommissionLedger::where('invoice_no', 'RD-' . $entry->id)->count(), 'running it again adds nothing');
    }

    public function test_support_chat_list_carries_reply_flag_and_last_message_without_row_queries(): void
    {
        $member = Member::firstOrFail();
        $support = app(SupportService::class);
        $thread = $support->userMessage(null, $member, 'My renewal is not showing')->thread;
        $support->supportReply($thread, 'We are checking it now', $this->admin()->id);
        $quiet = $support->userMessage(null, Member::create(['member_code' => 'LJWCHAT2', 'name' => 'Second', 'phone' => '9444444444', 'status' => 'active',
            'joined_on' => '2026-10-01', 'rank_id' => $member->rank_id]),
            'Where is my QR')->thread;

        $rows = \App\Filament\Resources\SupportThreadResource::getEloquentQuery()->get()->keyBy('id');
        $this->assertTrue((bool) $rows[$thread->id]->staff_replied);
        $this->assertFalse((bool) $rows[$quiet->id]->staff_replied);
        $this->assertSame('We are checking it now', $rows[$thread->id]->last_body);
        $this->assertSame('Where is my QR', $rows[$quiet->id]->last_body);
        $this->assertTrue($rows[$thread->id]->relationLoaded('owner'));
        $this->assertInstanceOf(SupportThread::class, $rows[$thread->id]);

        $this->actingAs($this->admin());
        $this->get('/admin/login');
        Livewire::test(\App\Filament\Resources\SupportThreadResource\Pages\ListSupportThreads::class)
            ->assertCanSeeTableRecords([$thread, $quiet])
            ->assertSee('We are checking it now');
    }

    /** User 2026-11-25: the browser must not fill the staff form with the admin's own saved login. */
    public function test_staff_edit_password_is_not_offered_to_browser_autofill(): void
    {
        $staff = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => 'secret123', 'status' => 'active']);
        $staff->assignRole('support');
        $this->actingAs($this->admin());
        $html = $this->get('/admin/staff/' . $staff->id . '/edit')->assertSuccessful()->getContent();
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('data-lpignore="true"', $html);
    }
}
