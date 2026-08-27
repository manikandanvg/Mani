<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchSourceChangeRequest;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\ResellerCommission;
use App\Models\StockTransfer;
use App\Models\StockTransferMargin;
use App\Services\BranchOrderService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    /** District selling gold earns the district-level % into stock_trans_margin. */
    public function test_seller_earns_per_level_margin_on_transfer(): void
    {
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $district = Branch::create(['name' => 'District', 'country' => 'IN', 'level' => 'district', 'source_branch_id' => $hq->id, 'is_active' => true]);
        $taluk = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $district->id, 'is_active' => true]);

        $gold = CatalogProduct::create(['code' => 'ST-G', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        StockTransferMargin::create([
            'catalog_product_id' => $gold->id,
            'zonal_pct' => 2, 'district_pct' => 3, 'taluk_pct' => 4, 'wholesaler_pct' => 5, 'reseller_pct' => 6,
        ]);

        // District sells 10g @ ₹5000/g = ₹50,000 → district rate 3% → ₹1,500.
        $transfer = app(StockTransferService::class)->record([
            'source_branch_id' => $district->id,
            'destination_branch_id' => $taluk->id,
            'catalog_product_id' => $gold->id,
            'weight' => 10,
            'unit_rate' => 5000,
        ]);

        $this->assertEquals('district', $transfer->seller_level);
        $this->assertEquals(50000, (float) $transfer->transfer_value);
        $this->assertEquals(3, (float) $transfer->margin_pct);
        $this->assertEquals(1500, (float) $transfer->margin_amount);

        $this->assertEquals(1500, (float) $district->fresh()->stock_trans_margin);
        $this->assertDatabaseHas('reseller_commissions', [
            'branch_id' => $district->id, 'com_type_id' => 4, 'com_value' => 1500,
        ]);
    }

    /** HQ's outbound transfers earn no stock-transfer margin. */
    public function test_hq_source_earns_no_margin(): void
    {
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $zonal = Branch::create(['name' => 'Zonal', 'country' => 'IN', 'level' => 'zonal', 'source_branch_id' => $hq->id, 'is_active' => true]);

        $gold = CatalogProduct::create(['code' => 'ST-HQ', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        StockTransferMargin::create(['catalog_product_id' => $gold->id, 'zonal_pct' => 2, 'district_pct' => 3]);

        $transfer = app(StockTransferService::class)->record([
            'source_branch_id' => $hq->id,
            'destination_branch_id' => $zonal->id,
            'catalog_product_id' => $gold->id,
            'weight' => 10,
            'unit_rate' => 5000,
        ]);

        $this->assertEquals(0, (float) $transfer->margin_amount);
        $this->assertEquals(0, (float) $hq->fresh()->stock_trans_margin);
        $this->assertDatabaseMissing('reseller_commissions', ['com_type_id' => 4]);
    }

    /** Unit rate falls back to the live metal rate when not supplied. */
    public function test_unit_rate_defaults_to_live_metal_rate(): void
    {
        LiveRate::create(['country' => 'IN', 'gold' => 6000, 'silver' => 80, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $reseller = Branch::create(['name' => 'Reseller', 'country' => 'IN', 'level' => 'reseller', 'source_branch_id' => $hq->id, 'is_active' => true]);
        $buyer = Branch::create(['name' => 'Area', 'country' => 'IN', 'level' => 'area_dealer', 'source_branch_id' => $reseller->id, 'is_active' => true]);

        $gold = CatalogProduct::create(['code' => 'ST-LR', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        StockTransferMargin::create(['catalog_product_id' => $gold->id, 'reseller_pct' => 5]);

        // 2g × live ₹6000 = ₹12,000 → reseller 5% = ₹600.
        $transfer = app(StockTransferService::class)->record([
            'source_branch_id' => $reseller->id,
            'destination_branch_id' => $buyer->id,
            'catalog_product_id' => $gold->id,
            'weight' => 2,
        ]);

        $this->assertEquals(6000, (float) $transfer->unit_rate);
        $this->assertEquals(12000, (float) $transfer->transfer_value);
        $this->assertEquals(600, (float) $transfer->margin_amount);
    }

    /**
     * Fulfilling a multi-line branch order moves stock seller→buyer and earns the seller
     * the stock-transfer commission on every line — not a standalone single-item entry.
     */
    public function test_order_fulfilment_generates_transfers_and_commission_for_seller(): void
    {
        \App\Models\LiveRate::create(['country' => 'IN', 'gold' => 5000, 'silver' => 100, 'diamond' => 0, 'source' => 'manual', 'effective_at' => now()]);

        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $seller = Branch::create(['name' => 'District', 'country' => 'IN', 'level' => 'district', 'source_branch_id' => $hq->id, 'is_active' => true]);
        // buyer orders FROM the seller (district)
        $buyer = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $seller->id, 'is_active' => true]);

        $gold = CatalogProduct::create(['code' => 'OF-G', 'name' => ['en' => 'Gold'], 'material' => 'gold', 'gst_pct' => 3, 'is_active' => true]);
        $silver = CatalogProduct::create(['code' => 'OF-S', 'name' => ['en' => 'Silver'], 'material' => 'silver', 'gst_pct' => 3, 'is_active' => true]);
        StockTransferMargin::create(['catalog_product_id' => $gold->id, 'district_pct' => 3]);
        StockTransferMargin::create(['catalog_product_id' => $silver->id, 'district_pct' => 4]);

        $orders = app(BranchOrderService::class);
        $request = $orders->submit([
            'branch_id' => $buyer->id,
            'lines' => [
                ['catalog_product_id' => $gold->id, 'weight' => 10],
                ['catalog_product_id' => $silver->id, 'weight' => 20],
            ],
        ]);
        $orders->approve($request->fresh('lines'));

        // a transfer row per line, all seller→buyer (multi-item — not a single-item entry)
        $transfers = StockTransfer::where('source_branch_id', $seller->id)
            ->where('destination_branch_id', $buyer->id)->get();
        $this->assertCount(2, $transfers);

        // each line earns the seller's per-level % on its own value (rate-independent checks)
        $goldT = $transfers->firstWhere('catalog_product_id', $gold->id);
        $silverT = $transfers->firstWhere('catalog_product_id', $silver->id);
        $this->assertEquals(3, (float) $goldT->margin_pct);
        $this->assertEquals(4, (float) $silverT->margin_pct);
        $this->assertEquals(round((float) $goldT->transfer_value * 3 / 100, 2), (float) $goldT->margin_amount);
        $this->assertEquals(round((float) $silverT->transfer_value * 4 / 100, 2), (float) $silverT->margin_amount);

        // seller's earned balance + commission ledger both equal the summed margin
        $expected = round((float) $transfers->sum('margin_amount'), 2);
        $this->assertGreaterThan(0, $expected);
        $this->assertEquals($expected, (float) $seller->fresh()->stock_trans_margin);
        $this->assertEquals($expected, (float) \App\Models\ResellerCommission::where('branch_id', $seller->id)->where('com_type_id', 4)->sum('com_value'));

        // buyer received the goods, seller shipped them
        $this->assertEquals(10, (float) \App\Models\Stock::where('branch_id', $buyer->id)->where('catalog_product_id', $gold->id)->value('quantity'));
        $this->assertEquals(-10, (float) \App\Models\Stock::where('branch_id', $seller->id)->where('catalog_product_id', $gold->id)->value('quantity'));
    }

    /** The source dropdown offers only branches ABOVE you in the chain, plus Head Office. */
    public function test_source_candidates_are_upstream_levels_plus_hq(): void
    {
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $zonal = Branch::create(['name' => 'Zonal A', 'country' => 'IN', 'level' => 'zonal', 'is_active' => true]);
        $district = Branch::create(['name' => 'District A', 'country' => 'IN', 'level' => 'district', 'is_active' => true]);
        $district2 = Branch::create(['name' => 'District B', 'country' => 'IN', 'level' => 'district', 'is_active' => true]);
        $taluk = Branch::create(['name' => 'Taluk A', 'country' => 'IN', 'level' => 'taluk', 'is_active' => true]);

        // A district may source from HQ + zonal only — not peers (other districts), not lower (taluk), not itself.
        $ids = $district->sourceCandidates()->pluck('id')->all();
        sort($ids);
        $expected = [$hq->id, $zonal->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        // Board ladder 2026-08-26 (final matrix): an Area Distributor may source from every
        // level except a Sub Dealer (and itself); a Sub Dealer from HQ, Taluka, Retailer, Wholesaler.
        $retailer = Branch::create(['name' => 'Retailer A', 'country' => 'IN', 'level' => 'reseller', 'is_active' => true]);
        $sub = Branch::create(['name' => 'Sub A', 'country' => 'IN', 'level' => 'sub_dealer', 'is_active' => true]);
        $area = Branch::create(['name' => 'Area', 'country' => 'IN', 'level' => 'area_dealer', 'is_active' => true]);
        $areaIds = $area->sourceCandidates()->pluck('id')->all();
        foreach ([$hq->id, $zonal->id, $district->id, $taluk->id, $retailer->id] as $id) {
            $this->assertContains($id, $areaIds);
        }
        $this->assertNotContains($sub->id, $areaIds);
        $this->assertNotContains($area->id, $areaIds);
        $subIds = $sub->sourceCandidates()->pluck('id')->all();
        sort($subIds);
        $exp = [$hq->id, $taluk->id, $retailer->id];
        sort($exp);
        $this->assertEquals($exp, $subIds);
    }

    /** Approving a source-change request re-points the branch's source. */
    public function test_approving_source_change_request_repoints_branch(): void
    {
        $hq = Branch::create(['name' => 'HQ', 'country' => 'IN', 'level' => 'hq', 'is_active' => true]);
        $taluk = Branch::create(['name' => 'Taluk', 'country' => 'IN', 'level' => 'taluk', 'source_branch_id' => $hq->id, 'is_active' => true]);
        $newSource = Branch::create(['name' => 'District B', 'country' => 'IN', 'level' => 'district', 'source_branch_id' => $hq->id, 'is_active' => true]);

        $req = BranchSourceChangeRequest::create([
            'branch_id' => $taluk->id,
            'current_source_branch_id' => $taluk->source_branch_id,
            'requested_source_branch_id' => $newSource->id,
            'status' => 'pending',
        ]);

        // mirror the resource's decide() approval
        $taluk->update(['source_branch_id' => $req->requested_source_branch_id]);
        $req->update(['status' => 'approved', 'decided_at' => now()]);

        $this->assertEquals($newSource->id, $taluk->fresh()->source_branch_id);
        $this->assertEquals('approved', $req->fresh()->status);
    }
}
