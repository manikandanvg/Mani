<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\ResellerCommission;
use App\Models\StockTransfer;
use App\Models\StockTransferMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock Transfer Margin (earning stream #7). When goods move one hop down the chain
 * (source/seller branch → destination/buyer branch) the SELLER earns
 * transfer_value × (the rate for the seller's LEVEL on that catalog item). The margin
 * accrues to the seller branch's `stock_trans_margin` and is logged as a
 * reseller_commission (com_type 4). HQ/super-admin earns nothing.
 *
 * A stock_transfers row is written the moment the transfer is recorded — the legacy
 * "create a record at the very moment it takes place".
 */
class StockTransferService
{
    /** com_type_id for stock-transfer margin (1=billing, 2=gold gm, 3=silver gm). */
    public const COM_TYPE_ID = 4;

    /** Headquarters branch id — its outbound transfers earn no margin. */
    public const HQ_BRANCH_ID = 1;

    /**
     * Record one transfer and settle the seller's margin in a single transaction.
     *
     * @param  array  $data  keys:
     *   source_branch_id, destination_branch_id, catalog_product_id,
     *   weight? (g, gold/silver), quantity? (units, vessel),
     *   unit_rate? (₹/g or ₹/unit; metal rate auto-resolved when omitted),
     *   transfer_value? (overrides the computed valuation),
     *   transfer_date?, created_by?
     */
    public function record(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $source = Branch::findOrFail($data['source_branch_id']);
            $product = CatalogProduct::findOrFail($data['catalog_product_id']);
            $material = $product->material;

            $weight = (float) ($data['weight'] ?? 0);
            $quantity = (float) ($data['quantity'] ?? 0);
            $unitRate = (float) ($data['unit_rate'] ?? $this->defaultRate($material));

            // Valuation the margin is computed on: metal lines bill by weight, vessels by qty.
            $value = (float) ($data['transfer_value'] ?? (
                $material === 'vessel' ? $quantity * $unitRate : $weight * $unitRate
            ));

            [$marginPct, $marginAmount] = $this->marginFor($source, $product, $value);

            $transfer = StockTransfer::create([
                'transfer_no' => $data['transfer_no'] ?? $this->nextTransferNo(),
                'transfer_date' => $data['transfer_date'] ?? Carbon::now()->toDateString(),
                'source_branch_id' => $source->id,
                'destination_branch_id' => $data['destination_branch_id'],
                'seller_level' => $source->level,
                'catalog_product_id' => $product->id,
                'material' => $material,
                'weight' => $weight,
                'quantity' => $quantity,
                'unit_rate' => $unitRate,
                'transfer_value' => round($value, 2),
                'margin_pct' => $marginPct,
                'margin_amount' => $marginAmount,
                'status' => 'passed',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            if ($marginAmount > 0) {
                // EP filter (board 2026-08-11): the seller branch's distributor is the
                // beneficiary — cap at their headroom; the transfer record keeps the
                // rate-card margin, only the payable commission is capped.
                $payable = app(CommissionService::class)->capByEp(
                    $source->distributorUser?->memberAccount,
                    (float) $marginAmount,
                    (string) $transfer->transfer_date,
                );
                if ($payable > 0) {
                    ResellerCommission::create([
                        'bill_date' => $transfer->transfer_date,
                        'invoice_no' => $transfer->transfer_no,
                        'com_type_id' => self::COM_TYPE_ID,
                        'user_id' => $transfer->created_by,
                        'branch_id' => $source->id,
                        'com_value' => $payable,
                        'status' => 'passed',
                    ]);
                    Branch::where('id', $source->id)->increment('stock_trans_margin', $payable);
                }
            }

            return $transfer;
        });
    }

    /**
     * The seller's margin on a transfer: rate is the per-level % on the item's rate card.
     * HQ (or any branch with no seller level / no rate card) earns nothing.
     *
     * @return array{0: float, 1: float}  [margin_pct, margin_amount]
     */
    protected function marginFor(Branch $source, CatalogProduct $product, float $value): array
    {
        if ($source->id === self::HQ_BRANCH_ID || ! in_array($source->level, Branch::SELLER_LEVELS, true)) {
            return [0.0, 0.0];
        }

        $card = StockTransferMargin::where('catalog_product_id', $product->id)->first();
        $pct = $card?->pctFor($source->level) ?? 0.0;
        if ($pct <= 0 || $value <= 0) {
            return [0.0, 0.0];
        }

        return [$pct, round($value * $pct / 100, 2)];
    }

    /** Live per-gram metal rate for valuation when the caller doesn't pass one. */
    protected function defaultRate(string $material, string $country = 'IN'): float
    {
        $column = $material === 'silver' ? 'silver' : 'gold';

        return (float) (LiveRate::where('country', $country)->latest('effective_at')->value($column) ?? 0);
    }

    protected function nextTransferNo(): string
    {
        return 'ST-' . strtoupper(Str::random(8));
    }
}
