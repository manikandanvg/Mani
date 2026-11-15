<?php

namespace Tests\Feature;

use App\Filament\Resources\BondResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\SalesInvoiceResource;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Support\IclTable;
use App\Livewire\SupportChatPanel;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\ContractSettlement;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\User;
use App\Services\BranchOrderService;
use App\Services\ContractSettlementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Lord's board 2026-11-15 — admin-panel batch:
 *  settlement by redeemable gold QR (bill × allocation % × settlement %), order cancel by the
 *  dealer + reject with a reason, the shared data-table behaviour, renamed / locked-down screens,
 *  rich-text support replies.
 */
class BoardAdminBatch20261115Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \App\Services\Activity\ActivityLogger::$enabled = true;   // the seeder switches it off
    }

    protected function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
    }

    protected function dealer(Branch $branch, string $email): User
    {
        Role::findOrCreate('distributor', 'web');
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'status' => 'active', 'branch_id' => $branch->id]);
        $u->assignRole('distributor');

        return $u;
    }

    /** A contract the way each system stores it: `amount` = bond share (old) or the whole bill (new). */
    protected function contract(float $bill, float $allocPct, float $qrPct, float $bondShare, bool $legacyAmount, string $start = '-25 months'): MemberContract
    {
        $branch = Branch::first();
        $plan = Plan::create([
            'code' => 'T' . random_int(1000, 9999), 'name' => ['en' => 'Board scheme'], 'plan_type' => 2, 'type' => 'digital',
            'min_value' => 0, 'allocation_bv' => 100, 'allocation_cont' => $bondShare, 'is_contract' => true, 'is_active' => true,
            'settlement_cycle_months' => 12, 'settlement_qr_pct' => $qrPct, 'settlement_alloc_pct' => $allocPct,
        ]);
        $member = Member::create([
            'member_code' => 'BM' . random_int(10000, 99999), 'name' => 'Board Member', 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::orderBy('depth')->value('id'),
        ]);
        $startDate = now()->modify($start)->toDateString();
        $bond = Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => $startDate,
            'value' => round($bill * $bondShare / 100, 2), 'epin_value' => $bill, 'invoice_no' => 'INV-' . $plan->code, 'status' => 'active',
        ]);

        return MemberContract::create([
            'contract_no' => 'LJC-' . $plan->code, 'bond_id' => $bond->id, 'member_id' => $member->id, 'plan_id' => $plan->id,
            'branch_id' => $branch->id, 'invoice_no' => $bond->invoice_no,
            'amount' => $legacyAmount ? $bond->value : $bill, 'start_date' => $startDate,
            'end_date' => now()->modify($start)->addMonthsNoOverflow(24)->toDateString(), 'content' => '', 'status' => 'active',
        ]);
    }

    /** The four lines of the board's sheet, to the rupee — whichever way the contract amount was stored. */
    public function test_settlement_rule_is_bill_times_allocation_times_settlement_percent(): void
    {
        $cases = [
            'Retailer' => [100000, 50, 72, 50, 36000.0],
            'Wholesaler' => [300000, 30, 80, 70, 72000.0],
            'Sub Dealer' => [100000, 75, 80, 25, 60000.0],
            'G12 Forward' => [100000, 100, 100, 100, 100000.0],
        ];
        foreach ($cases as $name => [$bill, $alloc, $pct, $bondShare, $expected]) {
            foreach ([true, false] as $legacyAmount) {
                $rule = ContractSettlementService::rule($this->contract($bill, $alloc, $pct, $bondShare, $legacyAmount));
                $this->assertSame($expected, $rule['worth'], $name . ($legacyAmount ? ' (old-system contract)' : ' (new contract)'));
                $this->assertSame(round($bill * $alloc / 100, 2), $rule['allocation'], $name);
            }
        }

        // the migration set the four schemes of the seeded catalogue, by name
        foreach (['%G5 Retailer%' => [50, 72], '%G24 Wholesaler%' => [30, 80], '%Sub Dealer%' => [75, 80], '%G12 Forward%' => [100, 100]] as $like => [$alloc, $pct]) {
            if ($plan = Plan::where('name', 'like', $like)->first()) {
                $this->assertEquals($alloc, (float) $plan->settlement_alloc_pct, $like);
                $this->assertEquals($pct, (float) $plan->settlement_qr_pct, $like);
            }
        }
    }

    public function test_expired_contract_settles_with_a_redeemable_gold_qr_and_nothing_goes_to_the_wallet(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 7000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $contract = $this->contract(100000, 50, 72, 50, true);
        $this->assertTrue(ContractSettlementService::settlesByQr($contract));
        $this->assertTrue(ContractSettlementService::canGenerate($contract));

        // the admin chooses: interim first …
        $this->assertSame(['interim', 'final'], array_keys(ContractSettlementService::stageOptions($contract)));
        $row = app(ContractSettlementService::class)->generateQr($contract, null, 'board rule', $this->admin()->id, stage: 'interim');

        $this->assertSame('gold_qr', $row->mode);
        $this->assertEquals(36000, (float) $row->amount);
        $this->assertEquals(36000, (float) $row->rule_amount);
        $qr = RedeemableQr::findOrFail($row->redeemable_qr_id);
        $this->assertSame('gold', $qr->qr_mode);
        $this->assertSame('settlement', $qr->kind);
        $this->assertSame('pending', $qr->status);                       // redeemable
        $this->assertEquals(36000, (float) $qr->cash_worth);
        $this->assertGreaterThan(0, (float) $qr->gram_worth);
        $this->assertEquals(0, (float) (MemberWallet::where('member_id', $contract->member_id)->value('cash_balance') ?? 0));

        // … the interim settlement leaves the contract running, and only the final remains
        $this->assertSame('interim', $row->stage);
        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(['final'], array_keys(ContractSettlementService::stageOptions($contract->fresh())));
        try {
            app(ContractSettlementService::class)->generateQr($contract->fresh(), null, null, $this->admin()->id, stage: 'interim');
            $this->fail('two interim settlements');
        } catch (HttpException $e) {
            $this->assertStringContainsString('interim settlement was already generated', $e->getMessage());
        }
        // … the final one — the admin may issue it the very next day — closes it
        $final = app(ContractSettlementService::class)->generateQr($contract->fresh(), null, null, $this->admin()->id, now()->addDay(), 'final');
        $this->assertSame('final', $final->stage);
        $this->assertEquals(36000, (float) $final->amount);
        $this->assertSame('closed', $contract->fresh()->status);
        $this->assertSame('closed', $contract->bond->fresh()->status);
        $this->assertFalse(ContractSettlementService::canGenerate($contract->fresh()));
        try {
            app(ContractSettlementService::class)->generateQr($contract->fresh(), null, null, $this->admin()->id);
            $this->fail('settled after the final');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already generated', $e->getMessage());
        }

        // an old-system contract that is 'closed' only because it expired — never settled — still
        // gets its settlement (the LEG-27 case); the final one then ends it
        $expired = $this->contract(100000, 50, 72, 50, true, '-30 months');
        $expired->update(['status' => 'closed']);
        $this->assertTrue(ContractSettlementService::canGenerate($expired->fresh()));
        $this->assertSame('final', ContractSettlementService::suggestedStage($expired->fresh()));
        app(ContractSettlementService::class)->generateQr($expired->fresh(), null, null, $this->admin()->id, stage: 'final');
        $this->assertFalse(ContractSettlementService::canGenerate($expired->fresh()));

        // the admin's choice, not the calendar: a contract only 3 months old can be settled too,
        // and the final alone (no interim) is allowed
        $young = $this->contract(100000, 50, 72, 50, true, '-3 months');
        $this->assertTrue(ContractSettlementService::canGenerate($young));
        $this->assertSame('interim', ContractSettlementService::suggestedStage($young));
        app(ContractSettlementService::class)->generateQr($young, null, null, $this->admin()->id, stage: 'final');
        $this->assertSame('closed', $young->fresh()->status);

        // the nightly engine leaves these schemes to Head Office
        $manual = $this->contract(100000, 50, 72, 50, false, '-13 months');
        app(\App\Services\SettlementService::class)->run();
        $this->assertNull($manual->fresh()->settled_on);
        $this->assertSame(0, RedeemableQr::where('bond_id', $manual->bond_id)->count());

        // the admin may approve another amount; the rule amount is kept beside it
        $other = $this->contract(300000, 30, 80, 70, false);
        $row2 = app(ContractSettlementService::class)->generateQr($other, 70000, null, $this->admin()->id);
        $this->assertEquals(70000, (float) $row2->amount);
        $this->assertEquals(72000, (float) $row2->rule_amount);

    }

    public function test_dealer_cancels_its_own_pending_order_and_a_rejection_carries_the_reason(): void
    {
        $buyer = Branch::create(['name' => 'Buyer', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true, 'digi_cash_balance' => 0]);
        $other = Branch::create(['name' => 'Other', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $buyerUser = $this->dealer($buyer, 'buyer@test.local');
        $otherUser = $this->dealer($other, 'other@test.local');
        $admin = $this->admin();
        $gold = CatalogProduct::firstOrCreate(['code' => 'G1BT'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        $order = fn () => app(BranchOrderService::class)->submit([
            'branch_id' => $buyer->id, 'requested_by' => $buyerUser->id, 'payment_type' => 'cash',
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 10]],   // above the order minimum
        ]);

        // only the ordering branch, only while pending
        $o1 = $order();
        $this->assertTrue(BranchOrderService::cancellableBy($o1, $buyerUser));
        $this->assertFalse(BranchOrderService::cancellableBy($o1, $otherUser));
        $this->assertFalse(BranchOrderService::cancellableBy($o1, $admin));
        app(BranchOrderService::class)->cancel($o1, $buyerUser, 'ordered by mistake');
        $o1->refresh();
        $this->assertSame('cancelled', $o1->status);
        $this->assertSame('ordered by mistake', $o1->decision_note);
        $this->assertSame($buyerUser->id, (int) $o1->cancelled_by);
        $this->assertNotNull($o1->cancelled_at);

        // User 2026-11-15: no refund on a cancel; the cancelled request leaves Head Office's list
        // but stays on the dealer's; and only an Order Form request is cancellable — not an
        // old-system order, a redemption restock, a customised order or a wallet-paid one.
        $this->assertEquals(0, (float) $buyer->fresh()->digi_cash_balance);
        $this->actingAs($admin);
        $this->assertFalse(\App\Filament\Resources\BranchOrderResource::getEloquentQuery()->whereKey($o1->id)->exists());
        $this->actingAs($buyerUser);
        $this->assertTrue(\App\Filament\Resources\BranchOrderResource::getEloquentQuery()->whereKey($o1->id)->exists());
        foreach (['source' => [BranchOrderService::SOURCE_LEGACY, BranchOrderService::SOURCE_REDEMPTION, BranchOrderService::SOURCE_CUSTOMIZE],
            'payment_type' => ['digi_cash']] as $column => $values) {
            foreach ($values as $value) {
                $o2 = $order();
                $o2->update([$column => $value]);
                $this->assertFalse(BranchOrderService::cancellableBy($o2->fresh(), $buyerUser), "{$column} = {$value}");
            }
        }

        // approved → no longer cancellable
        $o3 = $order();
        app(BranchOrderService::class)->approve($o3, $admin->id);
        $this->assertFalse(BranchOrderService::cancellableBy($o3->fresh(), $buyerUser));
        try {
            app(BranchOrderService::class)->cancel($o3->fresh(), $buyerUser);
            $this->fail('an approved order was cancelled');
        } catch (HttpException $e) {
            $this->assertStringContainsString('already decided', $e->getMessage());
        }

        // Head Office rejects with a reason: kept on the order and in the activity log
        $o4 = $order();
        app(BranchOrderService::class)->reject($o4, $admin->id, 'URN not found in the bank statement');
        $o4->refresh();
        $this->assertSame('rejected', $o4->status);
        $this->assertSame('URN not found in the bank statement', $o4->decision_note);
        $this->assertDatabaseHas('activity_logs', ['category' => 'orders', 'action' => 'rejected']);
        $this->assertTrue(\App\Models\ActivityLog::where('category', 'orders')->where('action', 'rejected')
            ->get()->contains(fn ($l) => str_contains((string) $l->summary, 'URN not found')));
    }

    public function test_tables_have_no_row_click_search_every_text_column_and_total_amounts(): void
    {
        $this->actingAs($this->admin());

        $table = Livewire::test(\App\Filament\Resources\MemberResource\Pages\ListMembers::class)->instance()->getTable();
        $this->assertInstanceOf(IclTable::class, $table);
        $member = Member::first() ?? Member::create(['member_code' => 'ROW1', 'name' => 'Row', 'phone' => '9000000000', 'joined_on' => now(), 'placement' => 'level', 'status' => 'active']);
        $this->assertNull($table->getRecordUrl($member));       // a row never opens on click
        $this->assertNull($table->getRecordAction($member));

        // a plain text column the resource did not mark searchable is searchable now
        $orders = Livewire::test(\App\Filament\Resources\BranchOrderResource\Pages\ListBranchOrders::class)->instance()->getTable();
        $this->assertTrue($orders->getColumn('payment_type')->isSearchable());
        $this->assertTrue($orders->getColumn('grand_total')->hasSummary());          // amount → footer total
        $this->assertTrue($orders->getColumn('branch.phone')->isCopyable('9000000000'));
        $this->assertArrayHasKey('branch_id', $orders->getFilters());                // generic branch filter added

        // Distributor List: nothing deletes; Contracts: no edit / delete / bulk; Sales register renamed
        $this->assertFalse(MemberResource::canDeleteAny());
        $this->assertFalse(MemberResource::canDelete($member));
        $this->assertSame([], $table->getBulkActions());
        $this->assertFalse(BondResource::canEdit(new Bond));
        $this->assertFalse(BondResource::canDeleteAny());
        $bonds = Livewire::test(\App\Filament\Resources\BondResource\Pages\ListBonds::class)->instance()->getTable();
        $this->assertNull($bonds->getAction('whatsapp'));
        $this->assertNull($bonds->getAction('edit'));
        $this->assertSame([], $bonds->getBulkActions());
        $this->assertSame('Manage Sales', SalesInvoiceResource::getNavigationLabel());
    }

    public function test_branch_dropdowns_show_the_full_name_and_search_every_part(): void
    {
        $b = Branch::create(['name' => 'LORD JEWELLER', 'incharge' => 'Kumar', 'city' => 'Erode', 'phone' => '9111111111', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);
        $this->assertSame('LORD JEWELLER (Kumar / Erode / 9111111111)', $b->pickLabel());
        foreach (['Kumar', 'Erode', '911111'] as $term) {
            $this->assertArrayHasKey($b->id, Branch::pickOptions($term), $term);
        }
        $this->assertSame($b->pickLabel(), Branch::fullOptions()[$b->id] ?? null);
    }

    public function test_tickets_are_one_entry_for_head_office_and_open_closed_for_a_dealer(): void
    {
        $this->actingAs($this->admin());
        $labels = array_map(fn ($i) => $i->getLabel(), SupportTicketResource::getNavigationItems());
        $this->assertSame(['Distributors Ticket'], $labels);

        $this->actingAs($this->dealer(Branch::first(), 'tickets@test.local'));
        $labels = array_map(fn ($i) => $i->getLabel(), SupportTicketResource::getNavigationItems());
        $this->assertSame(['Open Tickets', 'Closed Tickets'], $labels);
    }

    /** The chart options sit inside a double-quoted HTML attribute: one double quote empties the chart. */
    public function test_dashboard_charts_render_with_indian_money_options_intact(): void
    {
        foreach ([\App\Filament\Widgets\SalesRenewalChart::class, \App\Filament\Widgets\CommissionFlowChart::class] as $widget) {
            $html = Livewire::actingAs($this->admin())->test($widget)->assertOk()->html();
            $this->assertSame(1, preg_match('/x-data="chart\(\{(.*?)\}\)"/s', $html, $m), $widget);
            $this->assertStringContainsString("toLocaleString('en-IN'", $m[1], $widget);
        }
    }

    public function test_support_reply_keeps_rich_text_for_the_panel_and_plain_text_for_the_app(): void
    {
        $html = '<p>Dear <strong>member</strong>,</p><ul><li>Step one</li><li>Step two</li></ul><script>alert(1)</script>';
        $plain = SupportChatPanel::plain($html);
        $this->assertStringContainsString('Dear member,', $plain);
        $this->assertStringContainsString('• Step one', $plain);
        $this->assertStringNotContainsString('<', $plain);
        $this->assertStringNotContainsString('<script', SupportChatPanel::clean($html));
        $this->assertStringContainsString('<strong>member</strong>', SupportChatPanel::clean($html));
    }
}
