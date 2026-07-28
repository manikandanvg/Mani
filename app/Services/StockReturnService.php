<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\StockReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Branch → Head Office stock return. The branch-in-charge submits the items; on HQ
 * approval the stock physically moves branch → HQ and the voucher amount (priced at
 * the live rate like a stock order) is credited to the branch's Digi cash wallet —
 * spendable on future stock orders (payment_type digi_cash).
 */
class StockReturnService
{
    public const HQ_BRANCH_ID = 1;

    /** @param array $data keys: branch_id, created_by?, notes?, lines[[catalog_product_id, weight]] */
    public function submit(array $data): StockReturn
    {
        return DB::transaction(function () use ($data) {
            $branchId = (int) $data['branch_id'];
            abort_if($branchId === self::HQ_BRANCH_ID, 422, 'Head Office cannot return stock to itself.');

            $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();
            $pricer = app(BranchOrderService::class);

            $lines = collect($data['lines'] ?? [])
                ->filter(fn ($l) => ! empty($l['catalog_product_id']) && (float) ($l['weight'] ?? 0) > 0)
                ->values();
            abort_if($lines->isEmpty(), 422, 'Add at least one item to return.');

            $return = StockReturn::create([
                'return_no' => $this->nextReturnNo(),
                'branch_id' => $branchId,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $total = 0.0;
            foreach ($lines as $l) {
                $cp = CatalogProduct::findOrFail($l['catalog_product_id']);
                $weight = (float) $l['weight'];
                $this->assertBranchHolds($branchId, $cp, $weight);
                $p = $pricer->priceLine($cp, $weight, $rate);
                StockReturnLine::create([
                    'stock_return_id' => $return->id,
                    'catalog_product_id' => $cp->id,
                    'material' => $cp->material,
                    'weight' => $weight,
                    'rate' => $p['rate'],
                    'line_total' => $p['line_total'],
                ]);
                $total += $p['line_total'];
            }
            $return->update(['total_amount' => round($total, 2)]);

            return $return->fresh('lines');
        });
    }

    /** HQ approval: move the stock branch → HQ and credit the branch Digi cash wallet. */
    public function approve(StockReturn $return, ?int $approverId = null): void
    {
        DB::transaction(function () use ($return, $approverId) {
            $return = StockReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === 'pending', 422, 'Only pending returns can be approved.');

            $today = Carbon::now()->toDateString();
            foreach ($return->lines as $line) {
                $stock = Stock::where('branch_id', $return->branch_id)
                    ->where('catalog_product_id', $line->catalog_product_id)
                    ->lockForUpdate()->first();
                abort_if(! $stock || (float) $stock->quantity + 1e-6 < (float) $line->weight, 422,
                    'Branch no longer holds enough stock for line #' . $line->id . '.');

                $stock->decrement('quantity', (float) $line->weight);
                $hq = Stock::firstOrCreate(
                    ['branch_id' => self::HQ_BRANCH_ID, 'catalog_product_id' => $line->catalog_product_id],
                    ['quantity' => 0],
                );
                $hq->increment('quantity', (float) $line->weight);

                foreach ([[$return->branch_id, -1, $stock], [self::HQ_BRANCH_ID, 1, $hq]] as [$bid, $sign, $s]) {
                    StockMovement::create([
                        'branch_id' => $bid,
                        'catalog_product_id' => $line->catalog_product_id,
                        'type' => 'transfer',
                        'qty_change' => $sign * (float) $line->weight,
                        'balance_after' => $s->fresh()->quantity,
                        'ref_type' => 'stock_return',
                        'ref_id' => $return->id,
                        'moved_on' => $today,
                        'created_by' => $approverId ?? auth()->id(),
                    ]);
                }
            }

            // The voucher value lands in the branch's Digi cash wallet.
            Branch::where('id', $return->branch_id)->increment('digi_cash_balance', (float) $return->total_amount);

            $return->update([
                'status' => 'approved',
                'approved_by' => $approverId ?? auth()->id(),
                'approved_at' => Carbon::now(),
            ]);
        });
    }

    public function reject(StockReturn $return, ?int $approverId = null): void
    {
        abort_unless($return->status === 'pending', 422, 'Only pending returns can be rejected.');
        $return->update([
            'status' => 'rejected',
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => Carbon::now(),
        ]);
    }

    protected function assertBranchHolds(int $branchId, CatalogProduct $cp, float $weight): void
    {
        $held = (float) Stock::where('branch_id', $branchId)
            ->where('catalog_product_id', $cp->id)->value('quantity');
        abort_if($held + 1e-6 < $weight, 422,
            'Branch holds only ' . $held . ' of ' . $cp->code . ' — cannot return ' . $weight . '.');
    }

    protected function nextReturnNo(): string
    {
        $n = (int) StockReturn::max('id') + 1;

        return 'SRV-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
