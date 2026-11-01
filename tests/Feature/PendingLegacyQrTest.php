<?php

namespace Tests\Feature;

use App\Filament\Pages\PendingCashQr;
use App\Filament\Pages\PendingGoldQr;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\DigiGoldTxn;
use App\Models\DigiQueue;
use App\Models\DigiWithdrawal;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use App\Models\Stock;
use App\Models\User;
use App\Services\Redeem\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board 2026-11-01: legacy pending QRs are decided by HQ on two Commissions pages —
 * Pending Cash QR (cash wallet or digi-gold, per request) and Pending Gold QR (sent on
 * WhatsApp, redeemed at any branch at the metal price of the QR's own date).
 */
class PendingLegacyQrTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Branch $branch;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($this->admin);
        $this->get('/admin/login');
        \App\Services\Activity\ActivityLogger::$enabled = true;

        $this->branch = Branch::create(['name' => 'HQ Test', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $this->member = Member::create([
            'member_code' => 'LJW900', 'name' => 'Legacy Member', 'phone' => '9876543210', 'joined_on' => now(),
            'placement' => 'level', 'status' => 'active', 'rank_id' => Rank::where('depth', 0)->value('id'),
        ]);
        MemberWallet::create(['member_id' => $this->member->id, 'cash_balance' => 100, 'digi_gold_grams' => 1.0]);
    }

    protected function stageFixtures(): array
    {
        $plan = Plan::create(['code' => 'P202', 'name' => ['en' => 'G12 Forward contractors'], 'plan_type' => 2, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'is_contract' => true, 'is_active' => true]);
        $bond = Bond::create(['member_id' => $this->member->id, 'plan_id' => $plan->id, 'branch_id' => $this->branch->id,
            'bond_date' => '2026-03-10', 'value' => 25000, 'invoice_no' => '30070', 'status' => 'active']);

        // legacy invoice QRs: gold (with the legacy per-date rate), silver (rate derived), one of a deleted contract
        $gold = DigiQueue::create(['member_id' => $this->member->id, 'qr_code' => 'RXJOBMW', 'qr_mode' => 'gold', 'gram_worth' => 2.0, 'cash_worth' => 20000,
            'rate' => 10000, 'reference' => '30070', 'route' => 'INVOICE', 'delivery_status' => 'pending', 'qr_sent' => false, 'created_at' => '2026-03-10']);
        $silver = DigiQueue::create(['member_id' => $this->member->id, 'qr_code' => 'SILV0001', 'qr_mode' => 'silver', 'gram_worth' => 10.0, 'cash_worth' => 1500,
            'reference' => '30070', 'route' => 'INVOICE', 'delivery_status' => 'pending', 'qr_sent' => true, 'created_at' => '2026-04-01']);
        $orphan = DigiQueue::create(['member_id' => $this->member->id, 'qr_code' => 'ORPHAN01', 'qr_mode' => 'gold', 'gram_worth' => 0.5, 'cash_worth' => 5000,
            'rate' => 10000, 'reference' => '99999', 'route' => 'INVOICE', 'delivery_status' => 'pending', 'created_at' => '2026-05-01']);
        // cash side: one request with its dealer ticket, one request without a ticket, one ticket with no request
        $req = DigiWithdrawal::create(['member_id' => $this->member->id, 'gold_wt' => 0.25, 'worth' => 2500, 'status' => 'pending', 'created_at' => '2026-09-05']);
        DigiQueue::create(['member_id' => $this->member->id, 'qr_code' => 'CASH0001', 'qr_mode' => 'cash', 'gram_worth' => 0.25, 'cash_worth' => 2500,
            'route' => 'DIGIWD', 'delivery_status' => 'pending', 'created_at' => '2026-09-05']);
        $lonely = DigiWithdrawal::create(['member_id' => $this->member->id, 'gold_wt' => 0.1, 'worth' => 1000, 'status' => 'pending', 'created_at' => '2026-09-06']);
        DigiQueue::create(['member_id' => $this->member->id, 'qr_code' => 'CASH0002', 'qr_mode' => 'cash', 'gram_worth' => 0.4, 'cash_worth' => 4000,
            'rate' => 10000, 'route' => 'DIGIWD', 'delivery_status' => 'pending', 'created_at' => '2026-09-07']);

        return compact('plan', 'bond', 'gold', 'silver', 'orphan', 'req', 'lonely');
    }

    public function test_staging_carries_gold_qrs_and_orphan_cash_tickets_without_settling_anything(): void
    {
        $f = $this->stageFixtures();

        $this->artisan('legacy:stage-pending-qrs --dry-run')->assertSuccessful();
        $this->assertSame(0, RedeemableQr::count());
        $this->assertSame(2, DigiWithdrawal::count());

        $this->artisan('legacy:stage-pending-qrs')->assertSuccessful();

        // gold / silver QRs → redeemable, priced at their own date, queue rows parked
        $this->assertSame(3, RedeemableQr::where('source', 'legacy')->count());
        $g = RedeemableQr::where('qr_code', 'RXJOBMW')->firstOrFail();
        $this->assertSame($f['bond']->id, $g->bond_id);
        $this->assertSame($this->branch->id, $g->branch_id);
        $this->assertSame('30070', $g->invoice_no);
        $this->assertSame('gold', $g->qr_mode);
        $this->assertEquals(10000.0, (float) $g->frozen_rate);
        $this->assertSame('2026-03-10', $g->priced_on);
        $this->assertEquals(2.0, (float) $g->gram_worth);
        $this->assertEquals(20000.0, (float) $g->cash_worth);
        $this->assertSame('pending', $g->status);
        $this->assertFalse($g->qr_sent);
        $s = RedeemableQr::where('qr_code', 'SILV0001')->firstOrFail();
        $this->assertEquals(150.0, (float) $s->frozen_rate, 'no legacy rate → worth ÷ grams');
        $this->assertTrue($s->qr_sent);
        $this->assertNull(RedeemableQr::where('qr_code', 'ORPHAN01')->firstOrFail()->bond_id, 'contract deleted in legacy');
        $this->assertSame(3, DigiQueue::where('route', 'INVOICE')->where('delivery_status', DigiQueue::STATUS_STAGED)->count());

        // cash side: ticket matched to its request, lonely request kept, ticket-only row added; nothing paid
        $this->assertSame(3, DigiWithdrawal::where('status', 'pending')->count());
        $this->assertSame('CASH0001', $f['req']->fresh()->qr_code);
        $this->assertEquals(10000.0, (float) $f['req']->fresh()->rate);
        $this->assertNull($f['lonely']->fresh()->qr_code);
        $t = DigiWithdrawal::where('qr_code', 'CASH0002')->firstOrFail();
        $this->assertSame(DigiWithdrawal::SOURCE_TICKET, $t->source);
        $this->assertEquals(0.4, (float) $t->gold_wt);
        $this->assertEquals(4000.0, (float) $t->worth);
        $this->assertEquals(100.0, (float) MemberWallet::find($this->member->id)->cash_balance);

        // idempotent
        $this->artisan('legacy:stage-pending-qrs')->assertSuccessful();
        $this->assertSame(3, RedeemableQr::count());
        $this->assertSame(3, DigiWithdrawal::count());
    }

    public function test_pending_cash_qr_page_decides_cash_wallet_or_digi_gold(): void
    {
        $f = $this->stageFixtures();
        $this->artisan('legacy:stage-pending-qrs')->assertSuccessful();
        $req = $f['req']->fresh();
        $ticketOnly = DigiWithdrawal::where('qr_code', 'CASH0002')->firstOrFail();

        $this->get('/admin/pending-cash-qr')->assertSuccessful()->assertSee('Pending Cash QR');

        Livewire::test(PendingCashQr::class)
            ->assertCanSeeTableRecords([$req, $f['lonely'], $ticketOnly])
            ->callTableAction('toCash', $req)
            ->assertHasNoTableActionErrors()
            ->callTableAction('toDigi', $ticketOnly)
            ->assertHasNoTableActionErrors();

        $wallet = MemberWallet::find($this->member->id);
        $this->assertEquals(2600.0, (float) $wallet->cash_balance, '100 + ₹2,500 worth of the request');
        $this->assertEquals(1.4, (float) $wallet->digi_gold_grams, '1.0 + 0.4 g of the ticket');
        $this->assertSame('delivered', $req->fresh()->status);
        $this->assertSame(DigiWithdrawal::DECISION_CASH, $req->fresh()->decision);
        $this->assertSame($this->admin->id, $req->fresh()->decided_by);
        $this->assertSame(DigiWithdrawal::DECISION_DIGI, $ticketOnly->fresh()->decision);
        $txn = DigiGoldTxn::where('reference', 'WD-' . $ticketOnly->id)->firstOrFail();
        $this->assertEquals(0.4, (float) $txn->grams);
        $this->assertEquals(10000.0, (float) $txn->rate);
        $this->assertEquals(4000.0, (float) $txn->value);
        $this->assertSame('legacy_cash_qr', $txn->source);
        // both dealer tickets closed, the lonely request still waits
        $this->assertSame('redeemed', DigiQueue::where('qr_code', 'CASH0001')->value('delivery_status'));
        $this->assertSame('redeemed', DigiQueue::where('qr_code', 'CASH0002')->value('delivery_status'));
        $this->assertSame('pending', $f['lonely']->fresh()->status);

        // a second decision on the same row is refused
        Livewire::test(PendingCashQr::class)->filterTable('status', 'delivered')
            ->assertCanSeeTableRecords([$req->fresh()])->assertTableActionHidden('toCash', $req->fresh());
        $this->assertDatabaseHas('activity_logs', ['action' => 'pending_cash_qr_cash']);

        // bulk: the remaining request to cash
        Livewire::test(PendingCashQr::class)
            ->callTableBulkAction('bulkCash', [$f['lonely']])
            ->assertHasNoTableBulkActionErrors();
        $this->assertEquals(3600.0, (float) MemberWallet::find($this->member->id)->cash_balance);
        $this->assertSame(0, DigiWithdrawal::where('status', 'pending')->count());
    }

    public function test_pending_gold_qr_page_sends_on_whatsapp_and_redeems_at_the_qr_date_price_at_any_branch(): void
    {
        $f = $this->stageFixtures();
        $this->artisan('legacy:stage-pending-qrs')->assertSuccessful();
        config(['services.whatsapp.test_recipient' => null]);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->get('/admin/pending-gold-qr')->assertSuccessful()->assertSee('Pending Gold QR');
        $qr = RedeemableQr::where('qr_code', 'RXJOBMW')->firstOrFail();

        Livewire::test(PendingGoldQr::class)
            ->assertCanSeeTableRecords([$qr])
            ->callTableAction('send', $qr)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($qr->fresh()->qr_sent);
        $this->assertNotNull($qr->fresh()->sent_at);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'RXJOBMW') && str_contains(json_encode($r->data()), '10,000'));

        // today's gold is far dearer — the QR still redeems 2 g at its own ₹10,000/g, at ANOTHER branch
        LiveRate::create(['country' => 'IN', 'gold' => 15000, 'silver' => 250, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $other = Branch::create(['name' => 'Other Dealer', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $cp = CatalogProduct::create(['code' => 'LG1', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 0, 'gm_margin' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $other->id, 'catalog_product_id' => $cp->id, 'quantity' => 10]);

        $svc = app(RedemptionService::class);
        $ctx = $svc->lookup('RXJOBMW', $other->id);
        $this->assertSame('B', $ctx['mode'], 'legacy QR of a gold plan is NOT locked to the billing branch');
        $this->assertEquals(10000.0, $ctx['frozen_rate']);

        $otp = $svc->generateOtp($qr->fresh());
        $invoice = $svc->redeem([
            'qr_code' => 'RXJOBMW', 'branch_id' => $other->id, 'otp' => $otp,
            'lines' => [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 2]],
            'created_by' => $this->admin->id,
        ]);
        $this->assertInstanceOf(RedemptionInvoice::class, $invoice);
        $this->assertEquals(10000.0, (float) $invoice->gold_rate, 'invoice carries the QR-date price, not today\'s');
        $this->assertEquals(20000.0, (float) $invoice->grand_total);
        $this->assertSame('redeemed', $qr->fresh()->status);
        $this->assertEquals(8.0, (float) Stock::where('branch_id', $other->id)->value('quantity'));
    }
}
