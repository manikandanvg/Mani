<?php

namespace Tests\Feature;

use App\Filament\Widgets\BusinessOverview;
use App\Models\Branch;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Rank;
use App\Models\ResellerCommission;
use App\Models\User;
use App\Services\CommissionApprovalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User 2026-11-02: the dashboard "Approvals Pending" figure read ₹239 cr because it summed every
 * FUTURE-dated CBC coupon (one per bond per month up to 2028) and the legacy dealer-margin history.
 * Only what the approval gate can act on today is counted, and the card says what makes it up.
 */
class ApprovalsPendingFigureTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_due_and_approvable_items_are_counted_and_the_card_explains_the_figure(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::where('email', 'admin@lordicl.com')->firstOrFail());
        $branch = Branch::create(['name' => 'B', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $m = Member::create(['member_code' => 'LJW900', 'name' => 'M', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id')]);

        $ledger = fn (string $type, string $status, float $amt) => CommissionLedger::create(['type' => $type, 'member_id' => $m->id, 'amount' => $amt, 'net_amount' => $amt, 'status' => $status, 'earned_on' => '2026-10-01']);
        $ledger('IC', 'pending', 100);
        $ledger('IC', 'paid', 5000);          // history
        $ledger('GAP', 'pending', 200);
        $plan = \App\Models\Plan::create(['code' => 'P209', 'name' => ['en' => 'G11'], 'plan_type' => 1, 'type' => 'rd', 'min_value' => 1500, 'allocation_bv' => 0, 'allocation_cont' => 100, 'is_contract' => true, 'is_active' => true, 'validity_months' => 11]);
        $bond = \App\Models\Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => '2026-10-01', 'value' => 1500, 'invoice_no' => '1', 'status' => 'active']);
        $cbc = fn (string $date, float $worth) => CbcEntry::create(['member_id' => $m->id, 'bond_id' => $bond->id, 'code' => 'C' . random_int(10000, 99999), 'worth' => $worth, 'cbc_date' => $date, 'status' => 'pending']);
        $due = $cbc(now()->subDay()->toDateString(), 50);
        $future = $cbc(now()->addMonth()->startOfMonth()->toDateString(), 1000);
        $rc = fn (string $source, float $v) => ResellerCommission::create(['bill_date' => '2026-10-10', 'invoice_no' => 'N' . $v, 'com_type_id' => ResellerCommission::COM_BILL_MARGIN, 'branch_id' => $branch->id, 'com_value' => $v, 'reference_member_id' => $m->id, 'status' => 'passed', 'source' => $source]);
        $rc('legacy', 999);                   // settled history, never approvable
        $rc('new', 10);

        $stats = (new \ReflectionMethod(BusinessOverview::class, 'getStats'))->invoke(app(BusinessOverview::class));
        $card = collect($stats)->first(fn ($s) => $s->getLabel() === 'Approvals Pending');
        $this->assertSame('₹ 360.00', $card->getValue(), '100 IC + 200 GAP + 50 CBC due + 10 new margin; future CBC and legacy history left out');
        // the breakdown is a hover tooltip, not visible text on the card
        $this->assertSame('gross awaiting commission approval', $card->getDescription());
        $tip = $card->getExtraAttributes()['title'] ?? '';
        $this->assertStringContainsString('IC ₹ 100.00', $tip);
        $this->assertStringContainsString('GAP ₹ 200.00', $tip);
        $this->assertStringContainsString('CBC due ₹ 50.00', $tip);
        $this->assertStringContainsString('dealer margins ₹ 10.00', $tip);
        $this->assertStringNotContainsString('1,000', $tip, 'future coupons are neither counted nor mentioned');

        // the approval queue agrees: a future coupon is not offered for approval yet
        $ids = app(CommissionApprovalService::class)->query('CBC')->pluck('id')->all();
        $this->assertContains($due->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }
}
