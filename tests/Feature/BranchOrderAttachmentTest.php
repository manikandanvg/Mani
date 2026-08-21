<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Services\BranchOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Payment-proof attachments on stock orders (board phase-1, 2026-08-21):
 * files uploaded on the Order Form are recorded against the request for HQ.
 */
class BranchOrderAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_records_payment_proof_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('order-receipts/slip.pdf', '%PDF-1.4 fake');
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $hq->id, 'is_active' => true]);
        $gold = CatalogProduct::create(['code' => 'OA-G', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);

        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id,
            'payment_type' => 'online',
            'payment_remarks' => 'NEFT ref 12345',
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 2]],
            'attachments' => ['order-receipts/slip.pdf'],
            'attachment_names' => ['order-receipts/slip.pdf' => 'bank-slip.pdf'],
        ]);

        $this->assertDatabaseHas('branch_order_attachments', [
            'order_request_id' => $request->id,
            'path' => 'order-receipts/slip.pdf',
            'original_name' => 'bank-slip.pdf',
        ]);
        $this->assertCount(1, $request->attachments);
    }

    public function test_submit_without_attachments_still_works(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $hq->id, 'is_active' => true]);
        $gold = CatalogProduct::create(['code' => 'OA-G2', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);

        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $branch->id,
            'payment_type' => 'cash',
            'lines' => [['catalog_product_id' => $gold->id, 'weight' => 1]],
        ]);

        $this->assertDatabaseCount('branch_order_attachments', 0);
        $this->assertSame('pending', $request->status);
    }
}
