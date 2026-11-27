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

    /**
     * Price the lines of a return at the live rate — metal value + making + wastage + GST, the
     * same way a stock order is priced (board 2026-11-27). Used by the form's summary before the
     * return is sent and by submit(), so the branch sees exactly what Head Office will see.
     *
     * A line gives `quantity` (pieces) or, as before, `weight` (grams).
     *
     * @return array{lines: array<int, array>, metal_value: float, making: float, wastage: float, gst: float, total: float}
     */
    public function quote(array $lines): array
    {
        $rate = LiveRate::latestFor('IN') ?? LiveRate::query()->latest('id')->first();
        $pricer = app(BranchOrderService::class);
        $out = ['lines' => [], 'metal_value' => 0.0, 'making' => 0.0, 'wastage' => 0.0, 'gst' => 0.0, 'total' => 0.0];

        foreach ($lines as $l) {
            $cp = ! empty($l['catalog_product_id']) ? CatalogProduct::find($l['catalog_product_id']) : null;
            $pieces = (float) ($l['quantity'] ?? 0);
            $weight = (float) ($l['weight'] ?? 0);
            if (! $cp || ($pieces <= 0 && $weight <= 0)) {
                continue;
            }
            // pieces and grams always agree: one is worked out from the other
            if ($pieces > 0) {
                $weight = $cp->gramsFromPieces($pieces);
            } else {
                $pieces = $cp->piecesFromWeight($weight);
            }
            $p = $pricer->priceLine($cp, $weight, $rate);
            $metal = $cp->material === 'cash' ? (float) $p['subtotal'] : round($weight * (float) $p['rate'], 2);
            $making = round($metal * (float) $p['making_charge_pct'] / 100, 2);
            $wastage = round($metal * (float) $p['wastage_charge_pct'] / 100, 2);
            $line = [
                'cp' => $cp,
                'label' => trim($cp->code . ' — ' . (\App\Support\Translatable::pick($cp->name) ?? '')),
                'material' => $cp->material,
                'quantity' => round($pieces, 4),
                'unit_weight' => $cp->unitWeight(),
                'weight' => round($weight, 4),
                'rate' => (float) $p['rate'],
                'metal_value' => $metal,
                'making' => $making,
                'wastage' => $wastage,
                'gst' => (float) $p['gst'],
                'line_total' => (float) $p['line_total'],
            ];
            $out['lines'][] = $line;
            foreach (['metal_value', 'making', 'wastage', 'gst'] as $k) {
                $out[$k] = round($out[$k] + $line[$k], 2);
            }
            $out['total'] = round($out['total'] + $line['line_total'], 2);
        }

        return $out;
    }

    /**
     * @param array $data keys: branch_id, created_by?, notes?, bill_no?, attachments?,
     *                    lines[[catalog_product_id, quantity (pieces) | weight (grams)]]
     */
    public function submit(array $data): StockReturn
    {
        $return = DB::transaction(function () use ($data) {
            $branchId = (int) $data['branch_id'];
            abort_if($branchId === self::HQ_BRANCH_ID, 422, 'Head Office cannot return stock to itself.');

            $quote = $this->quote($data['lines'] ?? []);
            abort_if($quote['lines'] === [], 422, 'Add at least one item to return.');

            // the same item on two lines counts together against what the branch holds
            $asked = [];
            foreach ($quote['lines'] as $l) {
                $asked[$l['cp']->id] = ($asked[$l['cp']->id] ?? 0) + $l['quantity'];
            }
            foreach ($asked as $cpId => $pieces) {
                $this->assertBranchHolds($branchId, CatalogProduct::find($cpId), (float) $pieces);
            }

            $return = StockReturn::create([
                'return_no' => $this->nextReturnNo(),
                'branch_id' => $branchId,
                'status' => 'pending',
                'bill_no' => filled($data['bill_no'] ?? null) ? trim((string) $data['bill_no']) : null,
                'attachments' => array_values((array) ($data['attachments'] ?? [])) ?: null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($quote['lines'] as $l) {
                StockReturnLine::create([
                    'stock_return_id' => $return->id,
                    'catalog_product_id' => $l['cp']->id,
                    'material' => $l['material'],
                    'quantity' => $l['quantity'],
                    'weight' => $l['weight'],
                    'rate' => $l['rate'],
                    'metal_value' => $l['metal_value'],
                    'making' => $l['making'],
                    'wastage' => $l['wastage'],
                    'gst' => $l['gst'],
                    'line_total' => $l['line_total'],
                ]);
            }
            $return->update(['total_amount' => $quote['total']]);

            return $return->fresh('lines');
        });

        // HQ bell: a stock return awaits approval (board 2026-08-11).
        \App\Services\Push\Notifier::admins(
            'Stock return ' . $return->return_no . ' awaiting approval',
            ($return->branch?->name ?? 'A branch') . ' returned stock worth ₹'
                . \App\Support\Money::group((float) $return->total_amount) . '.',
            url: '/admin/stock-returns',
            category: 'order',
        );

        return $return;
    }

    /** HQ approval: move the stock branch → HQ and credit the branch Digi cash wallet. */
    public function approve(StockReturn $return, ?int $approverId = null): void
    {
        DB::transaction(function () use ($return, $approverId) {
            $return = StockReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            abort_unless($return->status === 'pending', 422, 'Only pending returns can be approved.');

            $today = Carbon::now()->toDateString();
            foreach ($return->lines as $line) {
                // Return lines are priced in grams; stock (and its guard) move in PIECES.
                $pieces = (float) $line->quantity > 0
                    ? (float) $line->quantity
                    : (\App\Models\CatalogProduct::find($line->catalog_product_id)
                        ?->piecesFromWeight((float) $line->weight) ?? (float) $line->weight);

                $stock = Stock::where('branch_id', $return->branch_id)
                    ->where('catalog_product_id', $line->catalog_product_id)->where('order_line_id', 0)
                    ->lockForUpdate()->first();
                abort_if(! $stock || (float) $stock->quantity + 1e-6 < $pieces, 422,
                    'Branch no longer holds enough stock for line #' . $line->id . '.');

                $stock->decrement('quantity', $pieces);
                $hq = Stock::firstOrCreate(
                    ['branch_id' => self::HQ_BRANCH_ID, 'catalog_product_id' => $line->catalog_product_id, 'order_line_id' => 0],
                    ['quantity' => 0],
                );
                $hq->increment('quantity', $pieces);

                foreach ([[$return->branch_id, -1, $stock], [self::HQ_BRANCH_ID, 1, $hq]] as [$bid, $sign, $s]) {
                    StockMovement::create([
                        'branch_id' => $bid,
                        'catalog_product_id' => $line->catalog_product_id,
                        'type' => 'transfer',
                        'qty_change' => $sign * $pieces,
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

        // Return-approved acknowledgement: Digi cash credited (board 2026-08-11).
        \App\Services\Push\Notifier::to(
            Branch::find($return->branch_id)?->distributorUser?->memberAccount,
            'wallet',
            'Stock return approved — ' . $return->return_no,
            \App\Support\Money::inr((float) $return->total_amount)
                . ' has been credited to your branch Digi cash wallet.',
            route: '/wallet',
        );
    }

    public function reject(StockReturn $return, ?int $approverId = null): void
    {
        abort_unless($return->status === 'pending', 422, 'Only pending returns can be rejected.');
        $return->update([
            'status' => 'rejected',
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => Carbon::now(),
        ]);

        \App\Services\Push\Notifier::to(
            \App\Models\Branch::find($return->branch_id)?->distributorUser?->memberAccount,
            'order',
            'Stock return rejected — ' . $return->return_no,
            'Head Office rejected your stock return of ₹' . \App\Support\Money::group((float) $return->total_amount)
                . '. The stock stays at your branch.',
            route: '/stock-returns/' . $return->id,
        );
    }

    /** Stock is counted in PIECES — the return is checked in pieces too (it was checked in grams). */
    protected function assertBranchHolds(int $branchId, CatalogProduct $cp, float $pieces): void
    {
        $held = (float) Stock::where('branch_id', $branchId)
            ->where('catalog_product_id', $cp->id)->where('order_line_id', 0)->value('quantity');
        $label = trim($cp->code . ' ' . (\App\Support\Translatable::pick($cp->name) ?? ''));
        $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
        abort_if($held + 1e-6 < $pieces, 422, sprintf(
            'Your branch holds only %s of %s — cannot return %s. Order stock from HQ first if needed.',
            $fmt($held), $label, $fmt($pieces)
        ));
    }

    protected function nextReturnNo(): string
    {
        $n = (int) StockReturn::max('id') + 1;

        return 'SRV-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
