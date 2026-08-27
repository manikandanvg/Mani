<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOrderAttachment;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\User;
use App\Services\BranchOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payment receipts are streamed through an auth-guarded route (board 2026-08-26,
 * item 2.3 — the live /storage/order-receipts/… 404). New uploads sit on the private
 * disk; receipts recorded earlier on the public disk still open through the same URL.
 */
class OrderAttachmentRouteTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $hq;

    protected Branch $branch;

    protected Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $this->hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $this->branch = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $this->hq->id, 'is_active' => true]);
        $this->other = Branch::create(['name' => 'Elsewhere', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $this->hq->id, 'is_active' => true]);
        Role::firstOrCreate(['name' => 'distributor', 'guard_name' => 'web']);
    }

    protected function user(?int $branchId, bool $distributor): User
    {
        $u = User::create(['name' => 'U', 'email' => uniqid() . '@t.local', 'password' => bcrypt('x'), 'status' => 'active', 'branch_id' => $branchId]);
        if ($distributor) {
            $u->assignRole('distributor');
        }

        return $u;
    }

    protected function orderWithReceipt(): BranchOrderAttachment
    {
        Storage::disk('local')->put('order-receipts/slip.jpg', 'JPEGDATA');
        $gold = CatalogProduct::create(['code' => 'AR-G', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $this->branch->id,
            'payment_type' => 'online',
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
            'attachments' => ['order-receipts/slip.jpg'],
            'attachment_names' => ['order-receipts/slip.jpg' => 'bank-slip.jpg'],
        ]);

        return $request->attachments()->firstOrFail();
    }

    public function test_new_receipts_are_recorded_on_the_private_disk_and_streamed_to_hq(): void
    {
        $att = $this->orderWithReceipt();

        $this->assertSame('local', $att->disk);
        $this->assertStringContainsString('/admin/order-attachments/' . $att->id, $att->url());

        $this->actingAs($this->user(null, false))
            ->get($att->url())
            ->assertSuccessful()
            ->assertHeader('Content-Disposition');
    }

    public function test_guests_are_redirected_and_unrelated_dealers_are_refused(): void
    {
        $att = $this->orderWithReceipt();

        $this->get($att->url())->assertRedirect();
        $this->actingAs($this->user($this->other->id, true))->get($att->url())->assertForbidden();
    }

    public function test_the_ordering_branch_and_its_supplier_dealer_can_open_the_receipt(): void
    {
        $att = $this->orderWithReceipt();

        $this->actingAs($this->user($this->branch->id, true))->get($att->url())->assertSuccessful();
        $this->actingAs($this->user($this->hq->id, true))->get($att->url())->assertSuccessful();
    }

    public function test_legacy_public_disk_receipts_still_open(): void
    {
        $att = $this->orderWithReceipt();
        Storage::disk('public')->put('order-receipts/old.pdf', '%PDF-1.4 old');
        $legacy = BranchOrderAttachment::create([
            'order_request_id' => $att->order_request_id, 'path' => 'order-receipts/old.pdf', 'disk' => 'public',
            'original_name' => 'old.pdf', 'mime' => 'application/pdf',
        ]);

        $this->actingAs($this->user(null, false))->get($legacy->url())
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_missing_file_is_a_clean_404(): void
    {
        $att = $this->orderWithReceipt();
        Storage::disk('local')->delete('order-receipts/slip.jpg');

        $this->actingAs($this->user(null, false))->get($att->url())->assertNotFound();
    }
}
