<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Member;
use App\Models\Plan;
use App\Models\RedeemableQr;
use App\Services\Qr\QrCodeService;
use App\Services\Qr\RedeemableQrService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RedeemableQrTest extends TestCase
{
    use RefreshDatabase;

    protected function makeBond(): Bond
    {
        $plan = Plan::create([
            'code' => 'P201', 'name' => ['en' => 'Dealer'], 'plan_type' => 1, 'type' => 'rd',
            'min_value' => 0, 'allocation_bv' => 100, 'is_redeem' => true, 'is_contract' => true, 'is_active' => true,
        ]);
        $rankId = \App\Models\Rank::create(['code' => 'MEMBER', 'name' => 'Member', 'depth' => 0, 'is_active' => true])->id;
        $member = Member::create([
            'member_code' => 'QR1', 'name' => 'QR Cust', 'phone' => '9000000009',
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rankId,
        ]);

        $branch = \App\Models\Branch::create(['name' => 'QR Shop', 'city' => 'Trichy', 'country' => 'IN', 'is_active' => true]);

        return Bond::create([
            'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
            'bond_date' => now()->toDateString(),
            'value' => 100000, 'epin_value' => 103000, 'invoice_no' => 'INV-QR-1', 'status' => 'active',
        ]);
    }

    public function test_qr_code_service_renders_png(): void
    {
        $png = app(QrCodeService::class)->png('STOCKQR-ABC123');
        $this->assertSame("\x89PNG", substr($png, 0, 4)); // PNG magic bytes
    }

    /** User 2026-11-20: a bill of the old system never gets a billing QR minted here. */
    public function test_old_system_bill_never_gets_a_new_billing_qr(): void
    {
        Storage::fake('public');
        $bond = $this->makeBond();
        $bond->update(['bond_date' => '2026-10-15', 'invoice_no' => '23442']);
        $svc = app(RedeemableQrService::class);

        // its QR was redeemed in the old system → no row here, and none may be minted
        $this->assertNull($svc->forBond($bond->fresh(), 7000.0));
        $this->assertSame(0, RedeemableQr::count());

        // its QR is still pending in the old system → the staged legacy QR is the one shown
        $staged = RedeemableQr::create([
            'member_id' => $bond->member_id, 'invoice_no' => '23442', 'qr_code' => 'OLDQR001', 'qr_mode' => 'gold',
            'source' => RedeemableQr::SOURCE_LEGACY, 'cash_worth' => 50000, 'status' => 'pending',
        ]);
        $this->assertSame($staged->id, $svc->forBond($bond->fresh(), 7000.0)->id);
        $this->assertSame(1, RedeemableQr::count());
    }

    /** User 2026-11-20: QR verification checks whether the QR — or its old-system bill — was redeemed before. */
    public function test_qr_verification_refuses_a_qr_redeemed_before(): void
    {
        $bond = $this->makeBond();
        $bond->update(['bond_date' => '2026-10-15', 'invoice_no' => '23442']);
        $mk = fn (string $code, ?string $kind, string $status = 'pending') => RedeemableQr::create([
            'bond_id' => $bond->id, 'member_id' => $bond->member_id, 'invoice_no' => '23442', 'qr_code' => $code,
            'qr_mode' => 'gold', 'kind' => $kind, 'cash_worth' => 50000, 'status' => $status,
        ]);
        $billing = $mk('REMINT01', 'billing');
        $renewal = $mk('RENEW001', 'renewal');
        $svc = app(\App\Services\Redeem\RedemptionService::class);

        // nothing on record against the bill yet → clean
        $this->assertNull($svc->redeemedBefore($billing));

        // the old system redeemed the bill (imported redemption invoice)
        \App\Models\RedemptionInvoice::create([
            'invoice_no' => '4598', 'invoice_date' => '2026-10-20', 'bond_id' => $bond->id, 'reference_no' => '23442',
            'member_id' => $bond->member_id, 'branch_id' => $bond->branch_id, 'payment_mode' => 'passed',
            'legacy_restock' => 'ordered', 'taxable_total' => 48543.69, 'tax_total' => 1456.31, 'grand_total' => 50000,
        ]);
        $this->assertStringContainsString('already redeemed in the old system', (string) $svc->redeemedBefore($billing));
        $this->assertNull($svc->redeemedBefore($renewal));   // a renewal QR is this system's own entitlement

        // a QR already redeemed / cancelled here says so, instead of "invalid QR"
        $this->assertStringContainsString('already redeemed', (string) $svc->redeemedBefore($mk('DONE0001', 'renewal', 'redeemed')));
        $this->assertStringContainsString('cancelled', (string) $svc->redeemedBefore($mk('VOID0001', 'renewal', 'cancelled')));

        try {
            $svc->lookup('REMINT01');
            $this->fail('a billing QR of a bill redeemed in the old system must not verify');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('already redeemed in the old system', $e->getMessage());
        }
    }

    public function test_an_old_system_redemption_cannot_be_cancelled(): void
    {
        $bond = $this->makeBond();
        $qr = RedeemableQr::create([
            'bond_id' => $bond->id, 'member_id' => $bond->member_id, 'invoice_no' => $bond->invoice_no,
            'qr_code' => 'OLDQR002', 'qr_mode' => 'gold', 'cash_worth' => 50000, 'status' => 'redeemed',
        ]);
        $invoice = \App\Models\RedemptionInvoice::create([
            'invoice_no' => '4598', 'invoice_date' => '2026-10-20', 'redeemable_qr_id' => $qr->id, 'bond_id' => $bond->id,
            'member_id' => $bond->member_id, 'branch_id' => $bond->branch_id, 'payment_mode' => 'passed',
            'legacy_restock' => 'ordered', 'taxable_total' => 48543.69, 'tax_total' => 1456.31, 'grand_total' => 50000,
        ]);

        $this->assertStringContainsString('old system',
            (string) app(\App\Services\Redeem\RedemptionCancelService::class)->blocker($invoice));
    }

    public function test_for_bond_is_idempotent_and_computes_worth(): void
    {
        Storage::fake('public');
        $bond = $this->makeBond();
        $svc = app(RedeemableQrService::class);

        $qr = $svc->forBond($bond, 7000.0);
        $again = $svc->forBond($bond, 7000.0);

        $this->assertEquals($qr->id, $again->id);                       // one QR per bond
        $this->assertEquals(1, RedeemableQr::where('bond_id', $bond->id)->count());
        $this->assertEquals(103000, (float) $qr->cash_worth);            // = epin_value (grand total)
        // grams = the gold the worth buys: 3 % GST out (no charge slab in this test) — user 2026-11-05
        $this->assertEqualsWithDelta(100000 / 7000, (float) $qr->gram_worth, 0.0001);
        $this->assertEquals('gold', $qr->qr_mode);
        $this->assertFalse((bool) $qr->qr_sent);
    }

    public function test_whatsapp_send_is_redirected_to_test_recipient(): void
    {
        config(['services.whatsapp.test_recipient' => '9677676034']);
        Http::fake(['*' => Http::response(['status' => 'ok', 'message' => 'sent'], 200)]);

        app(WhatsappSender::class)->sendText('9000000009', 'hello');

        // The customer's number is never used; the test recipient (91 + 9677676034) is.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'number=919677676034')
            && ! str_contains($req->url(), '9000000009'));
    }

    public function test_deliver_sends_contract_and_qr_then_marks_sent(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['status' => 'ok', 'message' => 'sent'], 200)]);

        $bond = $this->makeBond();
        $svc = app(RedeemableQrService::class);
        $qr = $svc->forBond($bond, 7000.0);

        $res = $svc->deliver($qr);

        $this->assertTrue($res['ok']);
        $this->assertTrue((bool) $qr->fresh()->qr_sent);
        $this->assertNotNull($qr->fresh()->sent_at);

        // Board 2026-08-11: WhatsApp is OTP-only — delivery is app push + inbox.
        // Two inbox entries land (contract + QR) carrying the download URLs; no
        // WhatsApp media HTTP calls go out at all.
        Http::assertSentCount(0);

        $inbox = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_id', $bond->member_id)
            ->get()
            ->map(fn ($n) => json_decode($n->data, true));

        $this->assertCount(2, $inbox);
        $categories = $inbox->pluck('category')->sort()->values()->all();
        $this->assertSame(['contract', 'qr'], $categories);

        $qrMsg = $inbox->firstWhere('category', 'qr');
        $this->assertSame((string) $qr->id, $qrMsg['data']['qr_id']);
        $this->assertNotEmpty($qrMsg['data']['image_url']);

        $contractMsg = $inbox->firstWhere('category', 'contract');
        $this->assertNotEmpty($contractMsg['data']['contract_url']);
    }
}
