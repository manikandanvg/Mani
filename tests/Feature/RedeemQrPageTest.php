<?php

namespace Tests\Feature;

use App\Filament\Pages\RedeemQr;
use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Smoke-tests the Redeem-QR Filament page: it renders and the Fetch action populates context. */
class RedeemQrPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_and_fetch_populates_context(): void
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        $branch = Branch::create(['name' => 'Main', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'a@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $branch->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $plan = Plan::create(['code' => '530', 'name' => ['en' => 'Plan 530'], 'plan_type' => 1, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'useraccess' => true, 'is_active' => true]);
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $member = Member::create(['member_code' => 'PG1', 'name' => 'PG1', 'phone' => '9000000000', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active']);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 50000, 'invoice_no' => 'INV-PG1', 'status' => 'active']);
        RedeemableQr::create(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => 'INV-PG1', 'qr_code' => 'QPG1', 'qr_mode' => 'cash', 'cash_worth' => 50000, 'status' => 'pending']);

        // Stock + catalog so the Mode-B redeem can actually run.
        $cp = CatalogProduct::create(['code' => 'PGG', 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 50, 'making_charge_pct' => 0, 'wastage_charge_pct' => 0, 'hallmark_charge' => 0, 'gst_pct' => 3, 'hsn_code' => '71082000', 'is_active' => true]);
        \App\Models\Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);

        $page = Livewire::test(RedeemQr::class)
            ->assertOk()
            ->set('data.qr_code', 'QPG1')
            ->set('data.branch_id', $branch->id)
            ->call('fetch')
            ->assertSet('ctx.mode', 'B')
            ->assertSet('ctx.dealer_eligible', true)
            ->assertSet('ctx.worth', 50000.0);

        // Generate the OTP the way "Send OTP" does, so we can complete the flow.
        $qr = RedeemableQr::where('qr_code', 'QPG1')->first();
        app(\App\Services\Redeem\RedemptionService::class)->generateOtp($qr);

        // Full redeem path: this calls $this->form->getState(), which previously dropped the
        // disabled qr_code/branch_id and failed. Now they are dehydrated and survive.
        $page->set('data.otp', $qr->fresh()->otp)
            ->set('data.lines', [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]])
            ->call('redeem')
            ->assertSet('ctx', null);   // reset after success

        $this->assertDatabaseHas('redemption_invoices', ['member_id' => $member->id, 'grand_total' => 100000]);
        $this->assertEquals('redeemed', $qr->fresh()->status);
    }

    /**
     * User 2026-11-13 (invoice 4598): the cart on the redeem screen IS the bill. A legacy QR is
     * priced at the rate of its own date — the screen used to cart it at today's rate, so the
     * operator saw one total (₹4,00,085) and the tax invoice came out at another (₹4,10,245.86).
     */
    public function test_legacy_qr_cart_total_on_screen_equals_the_invoice(): void
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        $branch = Branch::create(['name' => 'Main', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'b@lordicl', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $branch->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $plan = Plan::create(['code' => '531', 'name' => ['en' => 'Plan 531'], 'plan_type' => 1, 'type' => 'digital', 'min_value' => 0, 'allocation_bv' => 100, 'is_active' => true]);
        $rank = Rank::firstOrCreate(['code' => 'MEMBER'], ['name' => ['en' => 'Member'], 'depth' => 0, 'target_bv' => 0]);
        $member = Member::create(['member_code' => 'LG1', 'name' => 'LG1', 'phone' => '9000000001', 'joined_on' => now(), 'placement' => 'level', 'rank_id' => $rank->id, 'status' => 'active']);
        $bond = Bond::create(['member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id, 'bond_date' => now(), 'value' => 100000, 'invoice_no' => '4767', 'status' => 'active']);
        // carried over from legacy: gold frozen at ₹6,000/g (today's rate is ₹5,000)
        $qr = (new RedeemableQr)->forceFill(['bond_id' => $bond->id, 'member_id' => $member->id, 'branch_id' => $branch->id, 'invoice_no' => '4767',
            'qr_code' => 'QLEG1', 'qr_mode' => 'gold', 'source' => RedeemableQr::SOURCE_LEGACY, 'priced_on' => '2026-02-01', 'frozen_rate' => 6000,
            'cash_worth' => 100000, 'status' => 'pending']);
        $qr->save();

        $cp = CatalogProduct::create(['code' => 'LGG', 'name' => ['en' => 'Gold 1g'], 'material' => 'gold', 'gm_margin' => 50, 'making_charge_pct' => 2.5, 'wastage_charge_pct' => 2.5, 'hallmark_charge' => 0, 'gst_pct' => 3, 'hsn_code' => '71082000', 'is_active' => true]);
        \App\Models\Stock::create(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'quantity' => 100]);
        app(\App\Services\Redeem\RedemptionService::class)->generateOtp($qr);

        // 20 × 1 g @ ₹6,000 = 1,20,000 + 5 % making/wastage = ₹1,26,000, the 3 % GST inside it
        Livewire::test(RedeemQr::class)
            ->set('data.qr_code', 'QLEG1')
            ->set('data.branch_id', $branch->id)
            ->call('fetch')
            ->assertSet('ctx.frozen_rate', 6000.0)
            ->set('data.lines', [['catalog_product_id' => $cp->id, 'unit_weight' => 1, 'quantity' => 20]])
            ->assertSee('1,26,000.00')          // the cart total the operator sees …
            ->assertDontSee('1,05,000.00')      // … not the same cart at today's ₹5,000
            ->set('data.otp', $qr->fresh()->otp)
            ->call('redeem')
            ->assertSet('ctx', null);

        // … is the tax invoice, to the paisa: GST is taken out of it, never added on top
        $this->assertDatabaseHas('redemption_invoices', ['member_id' => $member->id, 'taxable_total' => 122330.10, 'tax_total' => 3669.90, 'grand_total' => 126000]);
    }
}
