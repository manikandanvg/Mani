<?php

namespace Tests\Feature;

use App\Filament\Resources\MemberContractResource\Pages\ListMemberContracts;
use App\Filament\Resources\MemoResource\Pages\CreateMemo;
use App\Filament\Resources\RedemptionInvoiceResource\Pages\ListRedemptionInvoices;
use App\Filament\Resources\StockResource\Pages\ListStock;
use App\Filament\Resources\SupportTicketResource\Pages\CreateSupportTicket;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\ContractSettlement;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Models\Memo;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedemptionInvoice;
use App\Models\RedemptionLine;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\ContractSettlementService;
use App\Services\CustomizeOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Board "Web-Application correction phase 2" (2026-08-28): contract settlement to the
 * wallet (+ app tab), Memo broadcast, multi-rank meeting audience, mandatory ticket
 * attachment, no restock on customized redemptions, "minimum" → "opening".
 */
class BoardPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->get('/admin/login');
    }

    protected function member(string $code, int $depth = 0): Member
    {
        $rank = Rank::firstOrCreate(
            ['depth' => $depth],
            ['code' => $depth === 0 ? 'MEMBER' : "R{$depth}", 'name' => ['en' => "Stage {$depth}"], 'target_bv' => $depth * 50000],
        );

        return Member::create([
            'member_code' => $code, 'name' => "Member {$code}", 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank->id,
        ]);
    }

    protected function plan(string $code = 'P999'): Plan
    {
        return Plan::firstOrCreate(['code' => $code], [
            'name' => ['en' => 'Test plan'], 'plan_type' => 1, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 12, 'cbc_value' => 0, 'cbc_count' => 0,
            'ic_schedule' => [], 'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 0,
            'billing_margin' => 0, 'is_redeem' => false, 'is_contract' => true, 'is_active' => true,
        ]);
    }

    protected function contract(Member $member, string $end, string $status = 'active'): MemberContract
    {
        return MemberContract::create([
            'contract_no' => 'CT-' . random_int(1000, 9999), 'member_id' => $member->id, 'plan_id' => $this->plan()->id,
            'amount' => 50000, 'start_date' => now()->subYear()->toDateString(), 'end_date' => $end, 'status' => $status,
        ]);
    }

    // ── Contracts → Generate settlement ─────────────────────────────────────────

    public function test_expired_contract_settlement_credits_the_wallet_once(): void
    {
        Notification::fake();
        $m = $this->member('CS1');
        $expired = $this->contract($m, now()->subDay()->toDateString());
        $running = $this->contract($m, now()->addMonth()->toDateString());
        $svc = app(ContractSettlementService::class);

        $this->assertTrue(ContractSettlementService::canGenerate($expired));
        $this->assertFalse(ContractSettlementService::canGenerate($running));

        $row = $svc->generate($expired, 12500.50, 'Maturity payout', $this->admin->id);

        $this->assertSame('12500.50', (string) $row->amount);
        $this->assertSame('12500.50', (string) MemberWallet::where('member_id', $m->id)->value('cash_balance'));
        $this->assertSame('12500.50', (string) MemberWallet::where('member_id', $m->id)->value('earning_total'));
        $this->assertSame('closed', $expired->fresh()->status);
        $this->assertNotNull($expired->fresh()->settled_on);
        Notification::assertSentTo($m, AppNotification::class);

        // Never twice, never on a running contract.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->generate($expired, 1, null, $this->admin->id);
    }

    public function test_running_contract_cannot_be_settled(): void
    {
        $m = $this->member('CS2');
        $running = $this->contract($m, now()->addMonth()->toDateString());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ContractSettlementService::class)->generate($running, 100, null, $this->admin->id);
    }

    public function test_generate_settlement_button_shows_only_for_expired_contracts_and_works(): void
    {
        Notification::fake();
        $m = $this->member('CS3');
        $expired = $this->contract($m, now()->subDay()->toDateString());
        $matured = $this->contract($m, now()->addMonth()->toDateString(), 'matured');
        $running = $this->contract($m, now()->addMonth()->toDateString());

        Livewire::actingAs($this->admin)->test(ListMemberContracts::class)
            ->assertTableActionVisible('generate_settlement', $expired)
            ->assertTableActionVisible('generate_settlement', $matured)
            ->assertTableActionHidden('generate_settlement', $running)
            ->callTableAction('generate_settlement', $expired, data: ['amount' => 7000, 'note' => 'ok'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('generate_settlement', $expired);

        $this->assertSame(1, ContractSettlement::where('member_contract_id', $expired->id)->count());
        $this->assertSame('7000.00', (string) MemberWallet::where('member_id', $m->id)->value('cash_balance'));
    }

    public function test_app_shows_contract_settlement_stream_and_summary(): void
    {
        Notification::fake();
        $m = $this->member('CS4');
        $c = $this->contract($m, now()->subDay()->toDateString());
        app(ContractSettlementService::class)->generate($c, 9000, 'Payout', $this->admin->id);

        Sanctum::actingAs($m, ['*']);
        $rows = $this->getJson('/api/v1/member/earnings?stream=SETTLEMENT')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('SETTLEMENT', $rows[0]['stream']);
        $this->assertEquals(9000, $rows[0]['amount']);
        $this->assertSame('paid', $rows[0]['status']);
        $this->assertSame($c->contract_no, $rows[0]['reference']);

        $dash = $this->getJson('/api/v1/member/dashboard')->assertOk()->json();
        $this->assertEquals(9000, $dash['earnings']['settlement']['total']);
        $this->assertEquals(9000, $dash['wallet']['cash_balance']);
    }

    // ── Community → Memo ─────────────────────────────────────────────────────────

    public function test_memo_is_pushed_to_every_app_registered_member_on_save(): void
    {
        Notification::fake();
        $withApp = $this->member('MM1');
        $withApp->deviceTokens()->create(['token' => 'tok-1', 'platform' => 'android', 'provider' => 'fcm']);
        $noApp = $this->member('MM2');

        Livewire::actingAs($this->admin)->test(CreateMemo::class)
            ->fillForm(['title' => 'Diwali offer', 'body' => 'Extra 1% on all gold schemes this week.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $memo = Memo::firstOrFail();
        $this->assertSame(1, $memo->sent_count);
        $this->assertNotNull($memo->sent_at);
        $this->assertSame($this->admin->id, $memo->created_by);
        Notification::assertSentTo($withApp, AppNotification::class, fn ($n) => $n->title === 'Diwali offer');
        Notification::assertNotSentTo($noApp, AppNotification::class);
    }

    public function test_conversations_and_messages_scaffolds_are_gone_from_community(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ConversationResource.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/MessageResource.php'));
        $this->actingAs($this->admin)->get('/admin/memos')->assertSuccessful();
        $this->actingAs($this->admin)->get('/admin/memos/create')->assertSuccessful();
        // Drive folders/files stay: they feed the app's Training library.
        $this->actingAs($this->admin)->get('/admin/drive-folders')->assertSuccessful();
    }

    // ── Live meetings: multi-select audience ────────────────────────────────────

    public function test_meeting_audience_is_the_exact_set_of_ranks_picked(): void
    {
        Meeting::create([
            'title' => 'Taluk + State call', 'join_url' => 'https://zoom.us/j/9', 'platform' => 'zoom',
            'scheduled_at' => now()->addDay(), 'duration_min' => 60, 'visibility' => 'members',
            'audience_ranks' => [1, 4], 'is_published' => true,
        ]);

        $sees = function (Member $m): bool {
            Sanctum::actingAs($m, ['*']);

            return collect($this->getJson('/api/v1/meetings')->assertOk()->json('upcoming'))
                ->pluck('title')->contains('Taluk + State call');
        };

        $this->assertTrue($sees($this->member('MA1', 1)));    // Taluk Admin — picked
        $this->assertTrue($sees($this->member('MA4', 4)));    // State Admin — picked
        $this->assertFalse($sees($this->member('MA3', 3)));   // Zonal — between the two, NOT picked
        $this->assertFalse($sees($this->member('MA0', 0)));   // plain distributor
    }

    // ── Support: attachment mandatory ───────────────────────────────────────────

    public function test_ticket_needs_an_attachment_and_it_streams_back_to_authorised_users(): void
    {
        Storage::fake('local');

        Livewire::actingAs($this->admin)->test(CreateSupportTicket::class)
            ->fillForm(['subject' => 'Printer jam', 'priority' => 'medium'])
            ->call('create')
            ->assertHasFormErrors(['attachments' => 'required']);

        Livewire::actingAs($this->admin)->test(CreateSupportTicket::class)
            ->fillForm([
                'subject' => 'Printer jam', 'priority' => 'medium',
                'attachments' => [UploadedFile::fake()->image('jam.jpg', 200, 200)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = SupportTicket::firstOrFail();
        $this->assertCount(1, $ticket->attachments);
        Storage::disk('local')->assertExists($ticket->attachments[0]);

        $this->actingAs($this->admin)->get(route('ticket.attachment', [$ticket->id, 0]))->assertOk();
        $this->actingAs($this->admin)->get(route('ticket.attachment', [$ticket->id, 5]))->assertNotFound();

        // A dealer from another branch is refused.
        $dealer = User::where('email', 'distributor@lordicl.com')->firstOrFail();
        $ticket->update(['branch_id' => Branch::where('id', '!=', $dealer->branch_id)->value('id'), 'opened_by' => $this->admin->id]);
        $this->actingAs($dealer)->get(route('ticket.attachment', [$ticket->id, 0]))->assertForbidden();
    }

    // ── Redemption: customized QR redeems as G10, no restock ────────────────────

    public function test_customized_redemption_has_no_restock_button(): void
    {
        $branch = Branch::firstOrFail();
        $m = $this->member('RD1');
        $custom = CustomizeOrderService::customProduct('gold');
        $coin = CatalogProduct::create(['code' => 'G1T', 'name' => ['en' => 'Gold coin 1 g'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);

        $mk = function (string $no, CatalogProduct $cp) use ($branch, $m) {
            $inv = RedemptionInvoice::create(['invoice_no' => $no, 'invoice_date' => now(), 'member_id' => $m->id, 'branch_id' => $branch->id,
                'taxable_total' => 100, 'cgst' => 0, 'sgst' => 0, 'grand_total' => 100, 'created_by' => null]);
            RedemptionLine::create(['redemption_invoice_id' => $inv->id, 'catalog_product_id' => $cp->id, 'description' => 'x',
                'material' => 'gold', 'unit_weight' => 10, 'quantity' => 1, 'rate' => 100, 'amount' => 100, 'line_total' => 100]);

            return $inv;
        };
        $customised = $mk('RDM-C1', $custom);
        $ordinary = $mk('RDM-O1', $coin);

        $this->assertTrue(\App\Filament\Resources\RedemptionInvoiceResource::isCustomized($customised));
        $this->assertFalse(\App\Filament\Resources\RedemptionInvoiceResource::isCustomized($ordinary));

        Livewire::actingAs($this->admin)->test(ListRedemptionInvoices::class)
            ->assertTableActionHidden('restock', $customised)
            ->assertTableActionVisible('restock', $ordinary)
            ->assertSee('Customized — no restock');
    }

    // ── Stock: "minimum" is now "opening" ──────────────────────────────────────

    public function test_stock_screen_says_opening_not_minimum(): void
    {
        $cp = CatalogProduct::create(['code' => 'G1S', 'name' => ['en' => 'Gold coin 1 g'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        \App\Models\Stock::create(['branch_id' => Branch::firstOrFail()->id, 'catalog_product_id' => $cp->id, 'quantity' => 2, 'min_qty' => 5]);

        Livewire::actingAs($this->admin)->test(ListStock::class)
            ->filterTable('branch_id', Branch::firstOrFail()->id)
            ->assertSee('Opening')
            ->assertDontSee('Minimum');
    }
}
