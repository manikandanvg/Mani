<?php

namespace Tests\Feature;

use App\Filament\Pages\Sales;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Complaint 2026-11-14 (live bill INV-B200-0110 → redemption invoice RDM-B200-0039, G10 Gold
 * Purchase Plan): 1 g gold was billed at ₹13,210 flat — no making, no wastage, no GST — while
 * the screen showed ₹14,286.62. The Sales page kept a line's making %, wastage %, hallmark,
 * GST %, material and piece weight in HIDDEN form fields, and a hidden field never reaches the
 * saved form state. The bill must be what the screen shows.
 */
class SalesPageChargesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gold_bill_is_saved_with_its_making_wastage_and_gst(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first() ?? User::first();
        $this->actingAs($admin);
        $branch = Branch::where('level', 'hq')->firstOrFail();
        LiveRate::create(['country' => 'IN', 'gold' => 13210, 'silver' => 205, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $gold = CatalogProduct::create(['code' => 'G1X', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1,
            'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 10]);
        $plan = Plan::create(['code' => 'PG10X', 'name' => ['en' => 'Gold Purchase'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0,
            'allocation_bv' => 100, 'allocation' => 100, 'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'ic_schedule' => [],
            'level_schedule' => [], 'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 0, 'is_active' => true]);
        $member = Member::firstOrFail();

        $t = Livewire::test(Sales::class)
            ->fillForm(['mode' => 'existing', 'branch_id' => $branch->id, 'bill_date' => now()->toDateString()])
            ->set('data.customer.member_id', $member->id);
        // pick the product the way the counter does: the page fills rate, weight and the charges
        $key = array_key_first($t->get('data.cart') ?: []) ?? 'a';
        $t->set("data.cart.{$key}.catalog_product_id", $gold->id)
            ->set('data.plan_id', $plan->id);
        // Second lock: even if the browser sends the line back WITHOUT its charges (what happened on
        // live), the bill is priced from the catalogue, not from the form.
        foreach (['making_charge_pct', 'wastage_charge_pct', 'gst_pct', 'material', 'unit_weight'] as $lost) {
            $t->set("data.cart.{$key}.{$lost}", null);
        }
        $t->call('generate')
            ->assertHasNoFormErrors();

        $invoice = SalesInvoice::where('plan_id', $plan->id)->firstOrFail();
        $line = SaleLine::where('invoice_id', $invoice->id)->firstOrFail();

        // 1 g × 13,210 = 13,210 + 2.5 % making + 2.5 % wastage = 13,870.50 + 3 % GST = 14,286.62
        $this->assertSame('gold', $line->material);
        $this->assertEquals(330.25, (float) $line->making);
        $this->assertEquals(330.25, (float) $line->wastage);
        $this->assertEquals(13870.50, (float) $invoice->cross_total);
        $this->assertEquals(416.12, (float) $invoice->tax_total);
        $this->assertEquals(14286.62, (float) $invoice->grand_total);
    }

    /**
     * User 2026-11-14: the product's own percentages stay the source; an item that carries none
     * takes the Charge Brackets slab for its weight; with neither, it is not billed at all.
     */
    public function test_charges_come_from_the_product_then_the_bracket_and_never_from_nothing(): void
    {
        $own = CatalogProduct::create(['code' => 'CH1', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1,
            'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'gst_pct' => 3, 'is_active' => true]);
        $blank = CatalogProduct::create(['code' => 'CH2', 'name' => ['en' => '300 mg Gold'], 'material' => 'gold', 'default_weight' => 0.3,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'gst_pct' => 3, 'is_active' => true]);
        $cash = CatalogProduct::create(['code' => 'CH3', 'name' => ['en' => 'CASH'], 'material' => 'cash', 'gst_pct' => 0, 'is_active' => true]);

        $this->assertSame(['making_pct' => 2.5, 'wastage_pct' => 2.5, 'source' => 'product'], $own->charges());
        $this->assertSame('none', $blank->charges()['source']);
        $this->assertTrue($blank->lacksCharges());
        $this->assertFalse($cash->lacksCharges(), 'cash carries no charge by design');

        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 0.101, 'wt_to' => 0.3, 'making_pct' => 15, 'wastage_pct' => 15]);
        $this->assertSame(['making_pct' => 15.0, 'wastage_pct' => 15.0, 'source' => 'bracket'], $blank->fresh()->charges());
        $this->assertFalse($blank->fresh()->lacksCharges());
        // the product's own figures still win where it has them
        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 0.301, 'wt_to' => 8, 'making_pct' => 9, 'wastage_pct' => 9]);
        $this->assertSame('product', $own->fresh()->charges()['source']);
    }

    /** The service itself refuses a gold line billed at the bare metal price, whoever calls it. */
    public function test_the_billing_service_refuses_a_metal_line_without_its_charges_or_gst(): void
    {
        $this->seed(DatabaseSeeder::class);
        $branch = Branch::where('level', 'hq')->firstOrFail();
        $gold = CatalogProduct::create(['code' => 'G1Y', 'name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1,
            'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'gst_pct' => 3, 'is_active' => true]);
        Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $gold->id, 'quantity' => 10]);
        $plan = Plan::create(['code' => 'PG10Y', 'name' => ['en' => 'Gold Purchase'], 'plan_type' => 1, 'type' => 'gold', 'min_value' => 0,
            'allocation_bv' => 100, 'allocation' => 100, 'ic_schedule' => [], 'level_schedule' => [], 'is_active' => true]);
        $bill = fn (float $net, float $grand, string $phone) => app(\App\Services\SalesService::class)->generateInvoice([
            'branch_id' => $branch->id, 'plan_id' => $plan->id, 'gold_rate' => 13210,
            'customer' => ['name' => 'Guard ' . $phone, 'phone' => $phone],
            'cart' => [['catalog_product_id' => $gold->id, 'weight' => 1, 'rate' => 13210, 'qty' => 1, 'net_total' => $net, 'grand_total' => $grand]],
        ]);
        $refused = function (float $net, float $grand, string $phone) use ($bill): string {
            try {
                $bill($net, $grand, $phone);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                return $e->getMessage();
            }
            $this->fail('A short bill was accepted.');
        };

        // exactly the live bill INV-B200-0110: metal only, no charges, no GST
        $this->assertStringContainsString('making and wastage', $refused(13210, 13210, '9000000301'));
        // charges there, GST missing
        $this->assertStringContainsString('no GST', $refused(13870.50, 13870.50, '9000000302'));
        $this->assertSame(0, SalesInvoice::where('plan_id', $plan->id)->count());

        // the full bill goes through
        $this->assertEquals(14286.62, (float) $bill(13870.50, 14286.62, '9000000303')->grand_total);
    }

    /** Redemption prices with the same rule: the bracket when the product carries no charge. */
    public function test_redemption_uses_the_bracket_when_the_product_has_no_charge(): void
    {
        $svc = app(\App\Services\Redeem\RedemptionService::class);
        $rate = new LiveRate(['gold' => 10000, 'silver' => 100]);
        $blank = CatalogProduct::create(['code' => 'RB1', 'name' => ['en' => '300 mg Gold'], 'material' => 'gold', 'default_weight' => 0.3,
            'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'gst_pct' => 3, 'is_active' => true]);

        try {
            $svc->priceLine($blank, 0.3, 1, $rate);
            $this->fail('A gold item with no charge at all was priced.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('no making / wastage', $e->getMessage());
        }

        \App\Models\ChargeBracket::create(['material' => 'gold', 'wt_from' => 0.101, 'wt_to' => 0.3, 'making_pct' => 15, 'wastage_pct' => 15]);
        $line = $svc->priceLine($blank->fresh(), 0.3, 1, $rate);
        // 0.3 g × 10,000 = 3,000 + 15 % + 15 % = 3,900 + 3 % GST = 4,017
        $this->assertEquals([450.0, 450.0, 3900.0, 117.0, 4017.0], [$line['making'], $line['wastage'], $line['taxable'], $line['gst'], $line['line_total']]);
    }
}
