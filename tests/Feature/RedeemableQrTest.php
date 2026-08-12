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
        $this->assertEqualsWithDelta(103000 / 7000, (float) $qr->gram_worth, 0.0001);
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
