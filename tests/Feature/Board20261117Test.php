<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedemptionInvoice;
use App\Models\SalesInvoice;
use App\Services\Contract\ContractContentBuilder;
use App\Services\NetworkService;
use App\Support\Accounts\NextAccountsSource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Board 2026-11-17: calculator = billing split, BV audit / safe recompute, Sub Dealer contract
 * body, Manage Sales redeemed-invoice link, expense register → auditor report.
 */
class Board20261117Test extends TestCase
{
    use RefreshDatabase;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function subDealer(): Plan
    {
        return Plan::create([
            'code' => 'P213', 'name' => ['en' => 'Sub Dealer'], 'type' => 'digital', 'min_value' => 20000,
            'allocation' => 25, 'allocation_bv' => 75, 'allocation_cont' => 25, 'rank_factor' => 75, 'counts_for_rank' => true,
            'cbc_value' => 10, 'cbc_count' => 24, 'validity_months' => 24, 'level_com_duration' => 24,
            'is_redeem' => true, 'is_contract' => true, 'is_active' => true,
        ]);
    }

    protected function member(string $code, ?int $uplineId = null): Member
    {
        return Member::create([
            'member_code' => $code, 'name' => $code, 'phone' => '9' . str_pad((string) (++$this->seq), 9, '0'),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => Rank::where('depth', 0)->value('id'),
            'status' => 'active', 'upline_id' => $uplineId,
        ]);
    }

    public function test_calculator_splits_a_sub_dealer_bill_the_way_billing_does(): void
    {
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 12730, 'silver' => 150, 'effective_at' => now()]);
        $plan = $this->subDealer();

        $res = $this->postJson('/api/v1/calculator', ['plan_id' => $plan->id, 'amount' => 100000])->assertOk();

        $this->assertEquals(25000.0, $res->json('invoice_allocation'));    // the gold handed over — not 75,000
        $this->assertEquals(75000.0, $res->json('contract_allocation'));
        $this->assertEquals(10000.0, $res->json('cbc_monthly'));           // bill × 10 % EVERY month
        $this->assertEquals(240000.0, $res->json('cbc_total'));
        $this->assertStringContainsString('25%', $res->json('benefits.0.label'));
        $this->assertStringContainsString('75%', $res->json('benefits.1.label'));
    }

    public function test_sub_dealer_contract_carries_the_split_and_the_cbc_benefit(): void
    {
        $plan = $this->subDealer();
        $bond = Bond::create([
            'member_id' => $this->member('SD1')->id, 'plan_id' => $plan->id, 'bond_date' => now(),
            'value' => 25000, 'epin_value' => 100000, 'cbc_value' => 10000, 'cbc_count' => 24, 'lvlcom_count' => 24, 'status' => 'active',
        ]);

        $body = app(ContractContentBuilder::class)->build($bond->load('plan'));

        $this->assertStringContainsString('SPOT 916 HUID Gold Coins (or) Bar : 25%', $body);
        $this->assertStringContainsString('24 months Contract: 75%', $body);
        $this->assertStringContainsString('Monthly Company Group sales profit: Rs.10,000.00/- x 24 months', $body);
        $this->assertStringContainsString('Total Profit for 24 months', $body);
    }

    public function test_recompute_restores_blanked_figures_and_touches_no_correct_row(): void
    {
        $plan = Plan::create(['code' => 'PX', 'name' => ['en' => 'X'], 'type' => 'digital', 'min_value' => 0,
            'allocation' => 100, 'allocation_bv' => 100, 'rank_factor' => 100, 'counts_for_rank' => true,
            'validity_months' => 11, 'level_com_duration' => 11, 'is_active' => true]);
        $a = $this->member('A');
        $b = $this->member('B', $a->id);
        foreach ([[$a, 60000], [$b, 70000]] as [$m, $v]) {
            Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => $v, 'epin_value' => $v,
                'lvlcom_count' => 11, 'status' => 'active']);
        }
        $network = app(NetworkService::class);
        $network->recomputeAll();
        $this->assertEquals(70000.0, (float) $a->fresh()->gbv);

        // what an interrupted run used to leave behind
        DB::table('members')->where('id', $a->id)->update(['gbv' => 0, 'unpure_gbv' => 0, 'bv' => 0]);
        $stamp = Carbon::now()->subDay();
        DB::table('members')->update(['updated_at' => $stamp]);

        $this->artisan('bv:audit')->assertExitCode(0);                 // dry run writes nothing
        $this->assertEquals(0.0, (float) $a->fresh()->gbv);

        $this->artisan('bv:audit', ['--apply' => true])->assertExitCode(0);
        $a->refresh();
        $this->assertEquals(60000.0, (float) $a->bv);
        $this->assertEquals(70000.0, (float) $a->gbv);
        $this->assertEquals(70000.0, (float) $a->unpure_gbv);
        $this->assertEquals('TALUK_DIRECTOR', $a->rank->code);         // stage kept, never dropped and re-raised
    }

    public function test_manage_sales_finds_the_redeemed_invoices_of_a_bill(): void
    {
        $plan = $this->subDealer();
        $m = $this->member('R1');
        $sale = SalesInvoice::forceCreate(['invoice_no' => 'INV-HQ-0001', 'date' => now(), 'customer_member_id' => $m->id,
            'customer_name' => $m->name, 'plan_id' => $plan->id, 'grand_total' => 100000, 'payment_type' => 'cash', 'remarks' => 'SALES']);
        $bond = Bond::create(['member_id' => $m->id, 'plan_id' => $plan->id, 'bond_date' => now(), 'value' => 25000, 'epin_value' => 100000,
            'invoice_no' => 'INV-HQ-0001', 'lvlcom_count' => 24, 'status' => 'active']);
        $this->assertCount(0, $sale->redemptionInvoices);

        RedemptionInvoice::create(['invoice_no' => 'RDM-HQ-0001', 'invoice_date' => now(), 'bond_id' => $bond->id, 'member_id' => $m->id,
            'grand_total' => 25000]);

        $this->assertSame(['RDM-HQ-0001'], $sale->fresh()->redemptionInvoices->pluck('invoice_no')->all());
    }

    public function test_only_approved_expenses_reach_the_auditor_report(): void
    {
        $rank = Rank::where('code', 'ZONAL_DIRECTOR')->first();
        $rank->update(['reward_title' => ['en' => 'Car award'], 'reward_amount' => 800000, 'monthly_rent' => 15000]);
        $m = $this->member('Z1');

        $base = ['expense_date' => now()->toDateString(), 'payment_mode' => 'bank', 'member_id' => $m->id, 'rank_id' => $rank->id];
        Expense::create($base + ['category' => 'tbp_reward', 'title' => 'Car award — Zonal Admin', 'amount' => 800000, 'status' => Expense::APPROVED]);
        Expense::create($base + ['category' => 'office_rent', 'title' => 'Office rent', 'amount' => 15000, 'period_month' => now()->format('Y-m'), 'status' => Expense::APPROVED]);
        Expense::create($base + ['category' => 'other', 'title' => 'Not approved yet', 'amount' => 999, 'status' => Expense::SUBMITTED]);

        $rows = app(NextAccountsSource::class)->expenses(now()->startOfMonth(), now()->endOfMonth());

        $this->assertCount(2, $rows);
        $this->assertEquals(815000.0, $rows->sum('amount'));
        $this->assertSame(['rewards', 'rent'], $rows->pluck('line')->all());
        $this->assertStringStartsWith('EXP-', $rows->first()->no);
    }
}
