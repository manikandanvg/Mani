<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trade > Purchase: buy catalog items (at live rates) into a branch's stock. Stock
 * accumulates per (branch, catalog product); every change is logged to the movement
 * ledger. All amounts are recomputed server-side from the line inputs.
 */
class TradePurchaseService
{
    /**
     * @param  array  $data  ref_no?, purchase_date, vendor_id?, branch_id, payment_type?,
     *                       notes?, gold_rate?, silver_rate?, created_by?, lines[]
     */
    public function record(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $branchId = $data['branch_id'];
            $date = $data['purchase_date'] ?? Carbon::now()->toDateString();
            $userId = $data['created_by'] ?? auth()->id();

            $purchase = Purchase::create([
                'ref_no' => $data['ref_no'] ?? $this->nextRef(),
                'purchase_date' => $date,
                'vendor_id' => $data['vendor_id'] ?? null,
                'branch_id' => $branchId,
                'gold_rate' => $data['gold_rate'] ?? 0,
                'silver_rate' => $data['silver_rate'] ?? 0,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'gross_total' => 0,
                'gst_total' => 0,
                'grand_total' => 0,
            ]);

            $gross = 0.0;
            $gstTotal = 0.0;

            foreach ($data['lines'] ?? [] as $line) {
                $calc = $this->lineTotals($line);
                $gross += $calc['taxable'];
                $gstTotal += $calc['gst'];

                PurchaseLine::create([
                    'purchase_id' => $purchase->id,
                    'catalog_product_id' => $line['catalog_product_id'] ?? null,
                    'material' => $line['material'] ?? 'gold',
                    'description' => $line['description'] ?? null,
                    'weight' => (float) ($line['weight'] ?? 0),
                    'purity' => $line['purity'] ?? null,
                    'rate' => (float) ($line['rate'] ?? 0),
                    'making_charge_pct' => (float) ($line['making_charge_pct'] ?? 0),
                    'wastage_charge_pct' => (float) ($line['wastage_charge_pct'] ?? 0),
                    'hallmark_charge' => (float) ($line['hallmark_charge'] ?? 0),
                    'gst_pct' => (float) ($line['gst_pct'] ?? 0),
                    'line_total' => $calc['line_total'],
                ]);

                if (! empty($line['catalog_product_id'])) {
                    $this->addToStock(
                        $branchId,
                        (int) $line['catalog_product_id'],
                        (float) ($line['weight'] ?? 0),
                        (float) ($line['rate'] ?? 0),
                        $line['purity'] ?? null,
                        $purchase->id,
                        $date,
                        $userId,
                    );
                }
            }

            $purchase->update([
                'gross_total' => round($gross, 2),
                'gst_total' => round($gstTotal, 2),
                'grand_total' => round($gross + $gstTotal, 2),
            ]);

            return $purchase->fresh('lines');
        });
    }

    /** weight×rate + (making+wastage)% + hallmark, then GST on the taxable amount. */
    public function lineTotals(array $line): array
    {
        $weight = (float) ($line['weight'] ?? 0);
        $rate = (float) ($line['rate'] ?? 0);
        $base = $weight * $rate;
        $charges = $base * (((float) ($line['making_charge_pct'] ?? 0) + (float) ($line['wastage_charge_pct'] ?? 0)) / 100)
            + (float) ($line['hallmark_charge'] ?? 0);
        $taxable = $base + $charges;
        $gst = $taxable * ((float) ($line['gst_pct'] ?? 0) / 100);

        return [
            'taxable' => round($taxable, 2),
            'gst' => round($gst, 2),
            'line_total' => round($taxable + $gst, 2),
        ];
    }

    /** Accumulate stock for (branch, product) and log a +movement. */
    protected function addToStock(int $branchId, int $productId, float $qty, float $rate, ?string $purity, int $purchaseId, string $date, ?int $userId): void
    {
        $stock = Stock::firstOrNew(['branch_id' => $branchId, 'catalog_product_id' => $productId]);
        $stock->quantity = (float) $stock->quantity + $qty;
        $stock->last_rate = $rate;
        if ($purity) {
            $stock->purity = $purity;
        }
        $stock->save();

        StockMovement::create([
            'branch_id' => $branchId,
            'catalog_product_id' => $productId,
            'type' => 'purchase',
            'qty_change' => $qty,
            'balance_after' => $stock->quantity,
            'ref_type' => 'purchase',
            'ref_id' => $purchaseId,
            'moved_on' => $date,
            'created_by' => $userId,
        ]);
    }

    protected function nextRef(): string
    {
        $n = (int) Purchase::max('id') + 1;

        return 'PUR-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
