<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberContractResource\Pages\ListMemberContracts;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\ContractSettlement;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RdEntry;
use App\Models\RdSettlement;
use App\Models\RedeemableQr;
use App\Models\User;
use App\Services\Rd\GoldSavingSettlementService;
use App\Services\Redeem\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Settlements page, user 2026-11-19:
 *  every contract listed in sequence (the old system had no "settlement"); search by user ID,
 *  phone and contract number; the admin decides whether a QR is issued (or the settlement is
 *  only recorded as made by hand); a settlement QR is redeemed only at the contract's own
 *  branch — Head Office excepted.
 */
class SettlementsPage20261119Test extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $home;

    protected Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->hq = Branch::where('level', 'hq')->firstOrFail();
        $this->home = Branch::create(['name' => 'Home Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $this->other = Branch::create(['name' => 'Other Branch', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->actingAs(User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail());
    }

    protected function contract(array $plan, string $code, string $phone, string $end, array $contract = [], float $bill = 100000): MemberContract
    {
        $plan = Plan::create(array_merge([
            'code' => 'T' . random_int(1000, 9999), 'name' => ['en' => 'Scheme ' . $code], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'is_contract' => true, 'is_active' => true,
        ], $plan));
        $member = Member::create([
            'member_code' => $code, 'name' => 'Member ' . $code, 'phone' => $phone,
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::orderBy('depth')->value('id'),
        ]);
        $bond = Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $this->home->id, 'bond_date' => '2025-01-01',
            'value' => $bill, 'epin_value' => $bill, 'invoice_no' => 'INV-' . $code, 'status' => 'active',
        ]);

        return MemberContract::create(array_merge([
            'contract_no' => 'LJC-' . $code, 'bond_id' => $bond->id, 'member_id' => $member->id, 'plan_id' => $plan->id,
            'branch_id' => $this->home->id, 'invoice_no' => $bond->invoice_no, 'amount' => $bill,
            'start_date' => '2025-01-01', 'end_date' => $end, 'content' => '', 'status' => 'active',
        ], $contract));
    }

    protected function qrPlan(): array
    {
        return ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 72, 'settlement_alloc_pct' => 50];
    }

    public function test_page_lists_every_contract_in_sequence_and_searches_user_id_phone_and_contract_no(): void
    {
        $running = $this->contract([], 'UIDRUN1', '9000000001', now()->addYear()->toDateString());
        $expired = $this->contract([], 'UIDEXP2', '9000000002', now()->subMonth()->toDateString());
        $oldClosed = $this->contract([], 'UIDOLD3', '9000000003', now()->subYear()->toDateString(),
            ['status' => 'closed', 'source' => MemberContract::SOURCE_LEGACY]);

        Livewire::test(ListMemberContracts::class)
            ->assertCanSeeTableRecords([$oldClosed, $expired, $running], inOrder: true)     // all, earliest end first
            ->assertSee('9000000002')                                                         // phone is on the table
            ->searchTable('UIDEXP2')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$running, $oldClosed])
            ->searchTable('9000000003')->assertCanSeeTableRecords([$oldClosed])->assertCanNotSeeTableRecords([$running, $expired])
            ->searchTable('LJC-UIDRUN1')->assertCanSeeTableRecords([$running])->assertCanNotSeeTableRecords([$expired, $oldClosed])
            ->searchTable(null)
            ->filterTable('stage', 'unsettled')
            ->assertCanSeeTableRecords([$expired, $oldClosed])->assertCanNotSeeTableRecords([$running]);
    }

    public function test_approve_and_issue_qr_locks_the_qr_to_the_contracts_branch_and_head_office(): void
    {
        $contract = $this->contract($this->qrPlan(), 'QRLOCK1', '9000000011', now()->subDay()->toDateString());

        Livewire::test(ListMemberContracts::class)
            ->callTableAction('settle_gold_qr', $contract, data: ['stage' => 'final', 'amount' => 36000, 'issue_qr' => true])
            ->assertHasNoTableActionErrors();

        $row = ContractSettlement::where('member_contract_id', $contract->id)->firstOrFail();
        $this->assertSame('gold_qr', $row->mode);
        $qr = RedeemableQr::findOrFail($row->redeemable_qr_id);
        $this->assertSame($this->home->id, (int) $qr->only_branch_id);
        $this->assertSame('closed', $contract->fresh()->status);

        $svc = app(RedemptionService::class);
        $this->assertSame($qr->id, $svc->lookup($qr->qr_code, $this->home->id)['qr']->id);   // its own branch
        $this->assertSame($qr->id, $svc->lookup($qr->qr_code, $this->hq->id)['qr']->id);     // Head Office
        try {
            $svc->lookup($qr->qr_code, $this->other->id);
            $this->fail('Another branch must not redeem a settlement QR.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Home Branch', $e->getMessage());
        }
    }

    public function test_a_closed_billing_branch_hands_the_settlement_to_head_office(): void
    {
        $contract = $this->contract($this->qrPlan(), 'CLOSEDBR', '9000000051', now()->subDay()->toDateString());
        $this->home->update(['is_active' => false]);

        $row = app(\App\Services\ContractSettlementService::class)->generateQr($contract, null, null, null, stage: 'final');

        $this->assertSame($this->hq->id, (int) $row->qr->only_branch_id);
        $svc = app(RedemptionService::class);
        $this->assertSame($row->qr->id, $svc->lookup($row->qr->qr_code, $this->hq->id)['qr']->id);
        $this->expectException(HttpException::class);
        $svc->lookup($row->qr->qr_code, $this->home->id);
    }

    /** Branch Order Requests: the restock of a settlement QR's redemption is typed "Settlement Redemption". */
    public function test_restock_of_a_settlement_redemption_is_typed_apart_and_filterable(): void
    {
        $cp = \App\Models\CatalogProduct::create(['code' => 'GS1', 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 50,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'hsn_code' => '71082000', 'is_active' => true]);
        \App\Models\Stock::create(['branch_id' => $this->home->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        $svc = app(RedemptionService::class);
        $orders = app(\App\Services\BranchOrderService::class);
        $redeem = function (RedeemableQr $qr) use ($svc, $cp) {
            $svc->generateOtp($qr);

            return $svc->redeem(['qr_code' => $qr->qr_code, 'branch_id' => $this->home->id, 'otp' => $qr->fresh()->otp,
                'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 6]]]);
        };

        // a settlement QR, redeemed and restocked
        $contract = $this->contract($this->qrPlan(), 'SETRST1', '9000000061', now()->subDay()->toDateString());
        $row = app(\App\Services\ContractSettlementService::class)->generateQr($contract, null, null, null, stage: 'final');
        $settlementOrder = $orders->createFromRedemption($redeem($row->qr), null);

        // an ordinary QR, redeemed and restocked
        $plain = $this->contract([], 'PLAINR1', '9000000062', now()->addYear()->toDateString());
        $qr = RedeemableQr::create(['bond_id' => $plain->bond_id, 'member_id' => $plain->member_id, 'branch_id' => $this->home->id,
            'invoice_no' => 'INV-PLAINR1', 'qr_code' => 'PLAINQR1', 'qr_mode' => 'cash', 'cash_worth' => 36000, 'status' => 'pending']);
        $plainOrder = $orders->createFromRedemption($redeem($qr), null);

        $this->assertSame('Settlement Redemption', $settlementOrder->fresh()->typeLabel());
        $this->assertSame('Redemption', $plainOrder->fresh()->typeLabel());

        Livewire::test(\App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders::class)
            ->assertCanSeeTableRecords([$settlementOrder, $plainOrder])
            ->assertSee('Settlement Redemption')
            ->filterTable('source', 'settlement_redemption')
            ->assertCanSeeTableRecords([$settlementOrder])->assertCanNotSeeTableRecords([$plainOrder])
            ->filterTable('source', 'redemption')
            ->assertCanSeeTableRecords([$plainOrder])->assertCanNotSeeTableRecords([$settlementOrder]);
    }

    public function test_admin_may_settle_by_hand_without_issuing_a_qr(): void
    {
        $contract = $this->contract($this->qrPlan(), 'MANUAL1', '9000000021', now()->subDay()->toDateString());

        Livewire::test(ListMemberContracts::class)
            ->callTableAction('settle_gold_qr', $contract, data: ['stage' => 'final', 'amount' => 30000, 'issue_qr' => false])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('settle_gold_qr', $contract);

        $row = ContractSettlement::where('member_contract_id', $contract->id)->firstOrFail();
        $this->assertSame('manual', $row->mode);
        $this->assertNull($row->redeemable_qr_id);
        $this->assertEquals(30000, (float) $row->amount);
        $this->assertEquals(36000, (float) $row->rule_amount);
        $this->assertSame(0, RedeemableQr::where('bond_id', $contract->bond_id)->count());
        $this->assertEquals(0, (float) (MemberWallet::where('member_id', $contract->member_id)->value('cash_balance') ?? 0));
        $this->assertSame('closed', $contract->fresh()->status);
    }

    public function test_gold_saving_the_old_system_closed_is_settled_at_its_own_branch(): void
    {
        $rd = ['type' => 'rd', 'validity_months' => 11, 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 3, 'settlement_close' => true];
        $legacy = $this->contract($rd, 'GSOLD1', '9000000031', now()->subMonths(3)->toDateString(),
            ['status' => 'closed', 'settled_on' => now()->subMonths(3)->toDateString(), 'source' => MemberContract::SOURCE_LEGACY], 1000);
        $legacy->bond->update(['status' => 'closed']);
        foreach (range(1, 6) as $i) {
            RdEntry::create(['bond_id' => $legacy->bond_id, 'member_id' => $legacy->member_id, 'paid_on' => now()->subMonths(8)->toDateString(),
                'value' => 1000, 'due_count' => $i + 1, 'branch_id' => $this->home->id]);
        }
        // closed by THIS system (not the old one) stays closed
        $ours = $this->contract($rd, 'GSNEW2', '9000000032', now()->subMonths(3)->toDateString(), ['status' => 'closed'], 1000);

        $this->assertTrue(GoldSavingSettlementService::canSettle($legacy->fresh()));
        $this->assertFalse(GoldSavingSettlementService::canSettle($ours->fresh()));

        // the form cannot move the settlement to another branch
        Livewire::test(ListMemberContracts::class)
            ->assertTableActionHidden('settle_gold_saving', $ours)
            ->callTableAction('settle_gold_saving', $legacy, data: ['branch_id' => $this->other->id, 'issue_qr' => true])
            ->assertHasNoTableActionErrors();

        $row = RdSettlement::where('member_contract_id', $legacy->id)->firstOrFail();
        $this->assertSame($this->home->id, (int) $row->branch_id);
        $this->assertSame('qr', $row->mode);
        $this->assertEquals(7000, (float) $row->worth);                                    // 7 dues, gold, no bonus
        $this->assertSame($this->home->id, (int) $row->qr->only_branch_id);
        $this->assertFalse(GoldSavingSettlementService::canSettle($legacy->fresh()));      // once only
    }

    public function test_gold_saving_settled_by_hand_issues_no_qr(): void
    {
        $rd = ['type' => 'rd', 'validity_months' => 11, 'settlement_cycle_months' => 12, 'settlement_bonus_months' => 3, 'settlement_close' => true];
        $c = $this->contract($rd, 'GSMAN1', '9000000041', now()->subDay()->toDateString(), [], 1000);

        $row = app(GoldSavingSettlementService::class)->settle($c, $this->home->id, issueQr: false);

        $this->assertSame('manual', $row->mode);
        $this->assertNull($row->redeemable_qr_id);
        $this->assertEquals(1000, (float) $row->worth);
        $this->assertSame(0, RedeemableQr::where('bond_id', $c->bond_id)->count());
        $this->assertSame('closed', $c->fresh()->status);
    }
}
