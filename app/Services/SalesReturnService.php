<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\SalesReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\CustomizeOrderPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer sales returns (board 2026-08-26). A distributor takes back a customer's
 * coins / metal — typically the accumulated RD 100 mg gold coins — values them at the
 * live metal rate, and (when raised from the Customize Order Form) applies that value
 * as `coin_credit` on the order. The coins are collected at the agreed date & time
 * (→ branch stock), then relayed to the supplier when the order is approved (→ out
 * of the branch's inventory).
 */
class SalesReturnService
{
    /**
     * @param array $data keys: branch_id, member_id?, catalog_product_id?, material?,
     *                    quantity (pieces), collect_on?, notes?, created_by?
     */
    public function create(array $data): SalesReturn
    {
        $branchId = (int) $data['branch_id'];
        $cp = ! empty($data['catalog_product_id']) ? CatalogProduct::find($data['catalog_product_id']) : CustomizeOrderPricing::coinProduct();
        abort_if(! $cp, 422, 'No coin product is configured — set the RD coin catalog item under Commission Setup → Customize Order pricing.');

        $qty = (float) ($data['quantity'] ?? 0);
        abort_if($qty <= 0, 422, 'Enter how many coins / pieces the customer is returning.');

        $material = in_array($cp->material, ['gold', 'silver'], true) ? $cp->material : ($data['material'] ?? 'gold');
        $grams = round($cp->gramsFromPieces($qty), 4);
        abort_if($grams <= 0, 422, 'The selected item has no per-piece weight — pick a coin / bar product.');

        $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();
        $perGram = CustomizeOrderPricing::liveRate($material, $rate);
        abort_if($perGram <= 0, 422, 'No live ' . $material . ' rate configured — set it before valuing returned coins.');

        return SalesReturn::create([
            'return_no' => $this->nextReturnNo(),
            'branch_id' => $branchId,
            'member_id' => $data['member_id'] ?? null,
            'catalog_product_id' => $cp->id,
            'material' => $material,
            'quantity' => $qty,
            'grams' => $grams,
            'rate' => round($perGram, 2),
            'credit_value' => CustomizeOrderPricing::metalValue($material, $grams, $rate),
            'collect_on' => ! empty($data['collect_on']) ? Carbon::parse($data['collect_on']) : null,
            'status' => SalesReturn::STATUS_PENDING,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /** Preview the credit a coin return would carry, without persisting. */
    public function quote(?int $catalogProductId, float $qty): array
    {
        $cp = $catalogProductId ? CatalogProduct::find($catalogProductId) : CustomizeOrderPricing::coinProduct();
        if (! $cp || $qty <= 0) {
            return ['grams' => 0.0, 'rate' => 0.0, 'value' => 0.0, 'material' => $cp?->material ?? 'gold'];
        }
        $material = in_array($cp->material, ['gold', 'silver'], true) ? $cp->material : 'gold';
        $grams = round($cp->gramsFromPieces($qty), 4);

        return [
            'material' => $material,
            'grams' => $grams,
            'rate' => CustomizeOrderPricing::liveRate($material),
            'value' => CustomizeOrderPricing::metalValue($material, $grams),
        ];
    }

    /** The distributor physically received the coins: count them into branch stock. */
    public function markCollected(SalesReturn $return, ?int $userId = null): void
    {
        DB::transaction(function () use ($return, $userId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === SalesReturn::STATUS_PENDING, 422, 'Only pending returns can be marked collected.');

            if ($return->catalog_product_id) {
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $return->branch_id, 'catalog_product_id' => $return->catalog_product_id],
                    ['quantity' => 0],
                );
                $stock->increment('quantity', (float) $return->quantity);
                StockMovement::create([
                    'branch_id' => $return->branch_id,
                    'catalog_product_id' => $return->catalog_product_id,
                    'type' => 'sales_return',
                    'qty_change' => (float) $return->quantity,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'sales_return',
                    'ref_id' => $return->id,
                    'note' => 'Customer coins collected (' . $return->return_no . ')',
                    'moved_on' => Carbon::now()->toDateString(),
                    'created_by' => $userId ?? auth()->id(),
                ]);
            }

            $return->update([
                'status' => SalesReturn::STATUS_COLLECTED,
                'collected_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Pass the collected coins on to the supplier (order approved): out of the
     * distributor's stock, into the seller's when the seller is a branch (HQ or a
     * dealer); a missing seller means the coins simply leave the branch.
     */
    public function relay(SalesReturn $return, ?Branch $seller, ?int $userId = null): void
    {
        DB::transaction(function () use ($return, $seller, $userId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === SalesReturn::STATUS_COLLECTED, 422,
                'Coins on ' . $return->return_no . ' have not been collected yet — mark them collected on Sales Returns first.');

            if ($return->catalog_product_id) {
                $today = Carbon::now()->toDateString();
                $pieces = (float) $return->quantity;
                $stock = Stock::firstOrCreate(
                    ['branch_id' => $return->branch_id, 'catalog_product_id' => $return->catalog_product_id],
                    ['quantity' => 0],
                );
                $stock->decrement('quantity', $pieces);
                StockMovement::create([
                    'branch_id' => $return->branch_id,
                    'catalog_product_id' => $return->catalog_product_id,
                    'type' => 'transfer',
                    'qty_change' => -$pieces,
                    'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'sales_return',
                    'ref_id' => $return->id,
                    'note' => 'Coins relayed to supplier (' . $return->return_no . ')',
                    'moved_on' => $today,
                    'created_by' => $userId ?? auth()->id(),
                ]);

                if ($seller) {
                    $in = Stock::firstOrCreate(
                        ['branch_id' => $seller->id, 'catalog_product_id' => $return->catalog_product_id],
                        ['quantity' => 0],
                    );
                    $in->increment('quantity', $pieces);
                    StockMovement::create([
                        'branch_id' => $seller->id,
                        'catalog_product_id' => $return->catalog_product_id,
                        'type' => 'transfer',
                        'qty_change' => $pieces,
                        'balance_after' => $in->fresh()->quantity,
                        'ref_type' => 'sales_return',
                        'ref_id' => $return->id,
                        'note' => 'Customer coins received from ' . ($return->branch?->name ?? 'branch') . ' (' . $return->return_no . ')',
                        'moved_on' => $today,
                        'created_by' => $userId ?? auth()->id(),
                    ]);
                }
            }

            $return->update([
                'status' => SalesReturn::STATUS_RELAYED,
                'relayed_at' => Carbon::now(),
            ]);
        });
    }

    public function cancel(SalesReturn $return): void
    {
        abort_unless($return->status === SalesReturn::STATUS_PENDING, 422, 'Only pending returns can be cancelled.');
        $return->update(['status' => SalesReturn::STATUS_CANCELLED]);
    }

    protected function nextReturnNo(): string
    {
        $n = (int) SalesReturn::max('id') + 1;

        return 'SR-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
