<?php

namespace Tests\Feature;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Models\RedeemableQr;
use App\Models\ResellerCommission;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SalesCancelService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Board 2026-10-31 (item 8): a sales invoice is never edited; "delete" is a CANCEL that
 * restores the stock and reverses every activity of the bill.
 */
class SalesCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function plan(): Plan
    {
        return Plan::create([
            'code' => 'PCX', 'name' => ['en' => 'Cancel test plan'], 'plan_type' => 2, 'type' => 'gold',
            'min_value' => 0, 'allocation_bv' => 100, 'validity_months' => 11,
            'cbc_value' => 10, 'cbc_count' => 3, 'ic_schedule' => [5, 3], 'level_schedule' => [],
            'level_depth' => 0, 'level_com_duration' => 0, 'billing_margin' => 2,
            'is_active' => true, 'available_to' => 'all', 'is_contract' => true, 'is_redeem' => true,
        ]);
    }

    protected function bill(Branch $branch, Plan $plan, ?Member $upline = null): SalesInvoice
    {
        $cp = CatalogProduct::firstOrCreate(['code' => 'G1T'], ['name' => ['en' => '1 g Gold'], 'material' => 'gold', 'default_weight' => 1, 'gst_pct' => 3, 'is_active' => true]);
        Stock::updateOrCreate(['branch_id' => $branch->id, 'catalog_product_id' => $cp->id, 'order_line_id' => 0], ['quantity' => 50]);

        return app(SalesService::class)->generateInvoice([
            'branch_id' => $branch->id,
            'plan_id' => $plan->id,
            'gold_rate' => 7000,
            'upline_code' => $upline?->member_code,
            'referrer_code' => $upline?->member_code,
            'customer' => ['name' => 'Cancel Cust', 'phone' => '9' . random_int(100000000, 999999999), 'pan' => 'ABCDE1234F'],
            'cart' => [[
                'catalog_product_id' => $cp->id, 'material' => 'gold', 'weight' => 2, 'qty' => 2, 'rate' => 7000,
                'net_total' => 14000, 'grand_total' => 14420,
            ]],
        ]);
    }

    public function test_cancel_restores_stock_and_reverses_every_activity_of_the_bill(): void
    {
        $dealer = Branch::create(['name' => 'Dealer C', 'country' => 'IN', 'is_active' => true]);
        $upline = Member::firstOrFail();
        $plan = $this->plan();
        // the upline needs bonded value (earning headroom) for a Sales Incentive line to issue
        Bond::create(['member_id' => $upline->id, 'plan_id' => $plan->id, 'bond_date' => '2026-01-01', 'value' => 500000, 'status' => 'active']);
        $uplineGbvBefore = (float) $upline->fresh()->gbv;
        $invoice = $this->bill($dealer, $plan, $upline);
        $cp = CatalogProduct::where('code', 'G1T')->firstOrFail();
        $member = $invoice->customer;
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->firstOrFail();

        // what the bill did
        $this->assertSame(48.0, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertSame((int) $invoice->id, (int) StockMovement::where('ref_type', 'sale')->where('ref_id', $invoice->id)->value('ref_id'));
        $this->assertSame(3, CbcEntry::where('bond_id', $bond->id)->count());
        $this->assertGreaterThan(0, CommissionLedger::where('bond_id', $bond->id)->count());
        $this->assertGreaterThan(0, ResellerCommission::where('invoice_no', $invoice->invoice_no)->count());
        $this->assertGreaterThan(0, (float) $dealer->fresh()->bill_margin);
        $this->assertSame(14420.0, (float) $member->fresh()->bv);   // grand total as billed (legacy mygbv)
        $this->assertSame($uplineGbvBefore + 14420.0, (float) $upline->fresh()->gbv);
        $uplineTeamBefore = (int) $upline->fresh()->downline_count;
        $this->assertTrue(RedeemableQr::where('bond_id', $bond->id)->where('status', 'pending')->exists());

        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $cancelled = app(SalesCancelService::class)->cancel($invoice, $admin->id, 'Wrong customer');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Wrong customer', $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(50.0, (float) Stock::where('branch_id', $dealer->id)->where('catalog_product_id', $cp->id)->value('quantity'));
        $this->assertTrue(StockMovement::where('ref_type', 'sale_cancel')->where('ref_id', $invoice->id)->exists());
        $this->assertSame('cancelled', $bond->fresh()->status);
        $this->assertSame(0, CbcEntry::where('bond_id', $bond->id)->count());
        $this->assertSame(0, CommissionLedger::where('bond_id', $bond->id)->count());
        $this->assertSame(0, ResellerCommission::where('invoice_no', $invoice->invoice_no)->count());
        $this->assertSame(0.0, (float) $dealer->fresh()->bill_margin);
        $this->assertSame(0.0, (float) $member->fresh()->bv);
        $this->assertSame($uplineGbvBefore, (float) $upline->fresh()->gbv);
        $this->assertSame($uplineTeamBefore - 1, (int) $upline->fresh()->downline_count);
        $this->assertSame('cancelled', RedeemableQr::where('bond_id', $bond->id)->value('status'));
        $this->assertSame('cancelled', \App\Models\MemberContract::where('bond_id', $bond->id)->value('status'));
        $this->assertSame(1, SalesInvoice::count(), 'the bill stays on record');

        // the distributor is told
        $this->assertDatabaseHas('notifications', ['notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id]);

        // and it cannot be cancelled twice
        $this->expectException(HttpException::class);
        app(SalesCancelService::class)->cancel($cancelled, $admin->id);
    }

    public function test_cancel_is_refused_once_the_stock_qr_was_redeemed(): void
    {
        $dealer = Branch::create(['name' => 'Dealer C', 'country' => 'IN', 'is_active' => true]);
        $invoice = $this->bill($dealer, $this->plan());
        RedeemableQr::where('invoice_no', $invoice->invoice_no)->update(['status' => 'redeemed']);

        $svc = app(SalesCancelService::class);
        $this->assertFalse($svc->canCancel($invoice));
        $this->assertStringContainsString('redeemed', (string) $svc->blocker($invoice));
        try {
            $svc->cancel($invoice, null);
            $this->fail('A redeemed bill was cancelled.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('active', $invoice->fresh()->status);
    }

    public function test_invoice_resource_offers_cancel_only_to_the_super_admin_and_never_edit(): void
    {
        $dealer = Branch::create(['name' => 'Dealer C', 'country' => 'IN', 'is_active' => true]);
        $invoice = $this->bill($dealer, $this->plan());

        $admin = User::where('email', 'admin@lordicl.com')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/login');
        $this->assertFalse(\App\Filament\Resources\SalesInvoiceResource::canEdit($invoice));
        $this->assertFalse(\App\Filament\Resources\SalesInvoiceResource::canDelete($invoice));
        $this->assertTrue(\App\Filament\Resources\SalesInvoiceResource::canCancel($invoice));
        \Livewire\Livewire::test(\App\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices::class)
            // the list opens on HQ's bills; a mounted action resolves its record through the filtered query
            ->filterTable('branch_id', $dealer->id)
            ->assertTableActionVisible('cancelInvoice', $invoice)
            ->assertTableActionVisible('view', $invoice)
            ->assertTableActionDoesNotExist('edit')
            ->callTableAction('cancelInvoice', $invoice, data: ['reason' => 'Test cancel'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Invoice ' . $invoice->invoice_no . ' cancelled');
        $this->assertSame('cancelled', $invoice->fresh()->status);

        // a dealer login sees its bills read-only (board item 16)
        $dealerUser = User::create(['name' => 'Dealer C', 'email' => 'dealerc@test.local', 'password' => 'secret123', 'status' => 'active', 'branch_id' => $dealer->id]);
        $dealerUser->assignRole('distributor');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($dealerUser);
        $other = $this->bill($dealer, Plan::where('code', 'PCX')->firstOrFail());
        \Livewire\Livewire::test(\App\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices::class)
            ->assertCanSeeTableRecords([$other])
            ->assertTableActionHidden('cancelInvoice', $other)
            ->assertTableActionVisible('view', $other);
    }
}
