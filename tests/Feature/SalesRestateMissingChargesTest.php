<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\CommissionLedger;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\RedeemableQr;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Services\Redeem\RedemptionService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-11-14 — bills the Sales page saved without making, wastage and GST (live: 48 lines,
 * ₹50,393.27 short). Round trip: a correct bill is broken exactly the way live broke it, the
 * repair runs, and bill / bond / commission / BV / QR must come back to the correct figures.
 */
class SalesRestateMissingChargesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected CatalogProduct $gold;

    protected Plan $plan;

    protected Member $top;

    protected Member $mid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->branch = Branch::create(['name' => 'Dealer RS', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        LiveRate::create(['country' => 'IN', 'gold' => 13210, 'silver' => 205, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->gold = CatalogProduct::create(['code' => 'G1R', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1,
            'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'gst_pct' => 3, 'gm_margin' => 0, 'is_active' => true]);
        Stock::create(['branch_id' => $this->branch->id, 'catalog_product_id' => $this->gold->id, 'quantity' => 50]);
        // G10-style: bond = the whole bill, instant commission 2.5 % / 0.75 %, counts for ranking BV
        $this->plan = Plan::create(['code' => 'PG10R', 'name' => ['en' => 'Gold Purchase'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0,
            'allocation_bv' => 0, 'allocation_cont' => 0, 'allocation' => 100, 'ic_schedule' => ['2.5', '0.75'], 'level_schedule' => [],
            'counts_for_rank' => true, 'rank_factor' => 100, 'is_redeem' => true, 'is_contract' => false, 'is_active' => true]);

        $rank = \App\Models\Rank::orderBy('depth')->value('id');
        $mk = fn (string $code, ?int $upline) => Member::create(['member_code' => $code, 'name' => $code, 'phone' => '9' . random_int(100000000, 999999999),
            'joined_on' => now(), 'placement' => 'level', 'status' => 'active', 'rank_id' => $rank, 'upline_id' => $upline, 'referrer_id' => $upline]);
        $this->top = $mk('RSTOP', null);
        $this->mid = $mk('RSMID', $this->top->id);
        foreach ([$this->top, $this->mid] as $m) {   // earning headroom for the two uplines
            Bond::create(['member_id' => $m->id, 'plan_id' => $this->plan->id, 'branch_id' => $this->branch->id, 'bond_date' => now()->subMonth(),
                'value' => 5000000, 'invoice_no' => 'CAP-' . $m->member_code, 'status' => 'active']);
        }
    }

    /** A correct 1 g bill: 13,210 + 660.50 = 13,870.50 + 3 % = 14,286.62. */
    protected function bill(string $phone): SalesInvoice
    {
        return app(SalesService::class)->generateInvoice([
            'branch_id' => $this->branch->id, 'plan_id' => $this->plan->id, 'gold_rate' => 13210, 'silver_rate' => 205,
            'referrer_code' => $this->mid->member_code, 'upline_code' => $this->mid->member_code,
            'customer' => ['name' => 'Buyer ' . $phone, 'phone' => $phone],
            'cart' => [['catalog_product_id' => $this->gold->id, 'material' => 'gold', 'weight' => 1, 'rate' => 13210, 'qty' => 1, 'unit_weight' => 1,
                'net_total' => 13870.50, 'grand_total' => 14286.62, 'making' => 330.25, 'wastage' => 330.25]],
        ]);
    }

    /** Put the bill in the state live left it in: bare metal, no charges, no GST — and everything derived from that. */
    protected function breakLikeLive(SalesInvoice $invoice): void
    {
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        DB::table('sale_lines')->where('invoice_id', $invoice->id)->update(['material' => null, 'making' => 0, 'wastage' => 0, 'line_total' => 13210]);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['cross_total' => 13210, 'net_total' => 13210, 'cgst' => 0, 'sgst' => 0,
            'tax_total' => 0, 'grand_total' => 13210, 'received' => 13210]);
        DB::table('bonds')->where('id', $bond->id)->update(['value' => 13210, 'epin_value' => 13210]);
        DB::table('commission_ledger')->where('bond_id', $bond->id)->where('level', 1)->update(['amount' => 330.25]);   // 2.5 % of 13,210
        DB::table('commission_ledger')->where('bond_id', $bond->id)->where('level', 2)->update(['amount' => 99.08]);    // 0.75 %
        $short = 14286.62 - 13210;
        DB::table('members')->where('id', $bond->member_id)->decrement('bv', $short);
        DB::table('members')->where('id', $bond->member_id)->decrement('unpure_bv', $short);
        foreach ([$this->mid->id, $this->top->id] as $id) {
            DB::table('members')->where('id', $id)->decrement('gbv', $short);
            DB::table('members')->where('id', $id)->decrement('unpure_gbv', $short);
        }
        DB::table('redeemable_qrs')->where('bond_id', $bond->id)->update(['cash_worth' => 13210]);
    }

    protected function snapshot(SalesInvoice $invoice): array
    {
        $invoice = $invoice->fresh();
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        $line = SaleLine::where('invoice_id', $invoice->id)->firstOrFail();
        $f = fn ($v) => round((float) $v, 2);

        return [
            'line' => [$line->material, $f($line->making), $f($line->wastage), $f($line->line_total)],
            'bill' => [$f($invoice->cross_total), $f($invoice->tax_total), $f($invoice->cgst), $f($invoice->sgst), $f($invoice->grand_total), $f($invoice->received)],
            'bond' => [$f($bond->value), $f($bond->epin_value)],
            'ic' => CommissionLedger::where('bond_id', $bond->id)->where('type', 'IC')->orderBy('level')->get()->groupBy('level')->map(fn ($g) => $f($g->sum('amount')))->all(),
            'buyer' => [$f(Member::find($bond->member_id)->bv), $f(Member::find($bond->member_id)->unpure_bv)],
            'mid' => [$f($this->mid->fresh()->gbv), $f($this->mid->fresh()->unpure_gbv)],
            'top' => [$f($this->top->fresh()->gbv), $f($this->top->fresh()->unpure_gbv)],
            'qr' => $f(RedeemableQr::where('bond_id', $bond->id)->value('cash_worth')),
        ];
    }

    public function test_a_short_bill_is_restored_to_exactly_what_a_correct_bill_is(): void
    {
        $invoice = $this->bill('9000000401');
        $correct = $this->snapshot($invoice);
        $this->assertSame([13870.5, 416.12, 208.06, 208.06, 14286.62, 14286.62], $correct['bill']);
        $this->assertSame([1 => 346.76, 2 => 104.03], $correct['ic']);   // 2.5 % and 0.75 % of 13,870.50

        $this->breakLikeLive($invoice);
        $broken = $this->snapshot($invoice);
        $this->assertSame(13210.0, $broken['bill'][4]);

        // the read-only list sees it, the dry run changes nothing
        $this->artisan('sales:audit-missing-charges')->expectsOutputToContain('1 were saved without their charges')->assertSuccessful();
        $this->artisan('sales:restate-missing-charges')->expectsOutputToContain('DRY RUN: 1 bill(s) would be re-stated')->assertSuccessful();
        $this->assertSame($broken, $this->snapshot($invoice));

        // level 2 was already approved and paid at the short amount → its difference comes as a new pending row
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        DB::table('commission_ledger')->where('bond_id', $bond->id)->where('level', 2)->update(['status' => 'paid']);

        $this->artisan('sales:restate-missing-charges', ['--apply' => true])->expectsOutputToContain('APPLIED: 1 bill(s) re-stated')->assertSuccessful();
        $this->assertSame($correct, $this->snapshot($invoice));

        $level2 = CommissionLedger::where('bond_id', $bond->id)->where('level', 2)->orderBy('id')->get();
        $this->assertSame([['paid', 99.08], ['pending', 4.95]], $level2->map(fn ($r) => [$r->status, round((float) $r->amount, 2)])->all());
        $this->assertSame(1, CommissionLedger::where('bond_id', $bond->id)->where('level', 1)->count());   // pending row raised in place

        // running it again does nothing
        $this->artisan('sales:restate-missing-charges', ['--apply' => true])->expectsOutputToContain('nothing to re-state')->assertSuccessful();
        $this->assertSame($correct, $this->snapshot($invoice));
    }

    /** A purchase already redeemed: its redemption tax invoice copied the short line and is corrected too. */
    public function test_the_redemption_invoice_of_a_short_bill_is_corrected_with_it(): void
    {
        $invoice = $this->bill('9000000402');
        $this->breakLikeLive($invoice);
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();
        $qr = RedeemableQr::where('bond_id', $bond->id)->firstOrFail();
        $svc = app(RedemptionService::class);
        $svc->generateOtp($qr);
        $redemption = $svc->redeem(['qr_code' => $qr->qr_code, 'branch_id' => $this->branch->id, 'otp' => $qr->fresh()->otp]);
        // as on live (RDM-B200-0039): the bare line + 3 % = 13,606.30
        $this->assertEquals(13606.30, (float) $redemption->grand_total);

        $this->artisan('sales:restate-missing-charges', ['--apply' => true])->assertSuccessful();

        $redemption->refresh();
        $this->assertEquals([13870.50, 416.12, 14286.62], [(float) $redemption->taxable_total, (float) $redemption->tax_total, (float) $redemption->grand_total]);
        $line = $redemption->lines()->firstOrFail();
        $this->assertEquals([330.25, 330.25, 14286.62], [(float) $line->making, (float) $line->wastage, (float) $line->line_total]);
        $this->assertEquals(14286.62, (float) $invoice->fresh()->grand_total);
        $this->assertSame('redeemed', $qr->fresh()->status);
    }
}
