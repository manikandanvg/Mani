<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Complaint 2026-11-21 (Lord's board, LJW2417736232945): "ID was billed but no stock reduced
 * from the branch". The Sales page let the price box of a CASH line be typed, so some bills
 * were raised as Amount 1 × Price 1,00,000: the bill total, contract and commissions are right,
 * but the branch cash stock fell by ₹1 instead of ₹1,00,000 (live 16–21 Nov: 15 bills,
 * 4 branches, ₹16,59,985 not deducted).
 *
 * For every such ACTIVE bill this
 *   - restates the bill line as amount × ₹1 (the line total does not change), and
 *   - deducts the missing amount from the branch cash stock, with a stock movement dated the
 *     bill date and carrying the bill's id — so cancelling the bill later gives back exactly
 *     what was taken, and a second run finds nothing left to do.
 *
 *   php artisan stock:fix-cash-bill-deductions            dry run (bill by bill)
 *   php artisan stock:fix-cash-bill-deductions --apply    writes the corrections
 */
class StockFixCashBillDeductions extends Command
{
    protected $signature = 'stock:fix-cash-bill-deductions {--from=2026-11-01 : first bill date to check (new system go-live)} {--apply : write the corrections (default = dry run)}';

    protected $description = 'Deduct from the branch cash stock the bills whose cash line was typed as 1 × amount';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $lines = DB::table('sale_lines as l')
            ->join('sales_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('catalog_products as p', 'p.id', '=', 'l.catalog_product_id')
            ->where('p.material', 'cash')
            ->where('i.status', 'active')
            ->whereDate('i.date', '>=', (string) $this->option('from'))
            ->where('i.invoice_no', 'like', 'INV-%')          // new-system bills only
            ->where('l.rate', '<>', 1)
            ->orderBy('i.id')
            ->get(['l.id as line_id', 'l.qty', 'l.rate', 'l.line_total', 'l.catalog_product_id',
                'i.id as invoice_id', 'i.invoice_no', 'i.date', 'i.branch_id']);

        $branches = DB::table('branches')->pluck('name', 'id');
        $rows = [];
        $totalShort = 0.0;
        $running = [];   // "branch:product" => cash stock as the dry run walks the bills

        foreach ($lines as $l) {
            $amount = round((float) $l->qty * (float) $l->rate, 2);
            $taken = abs((float) StockMovement::where('ref_type', 'sale')->where('ref_id', $l->invoice_id)
                ->where('catalog_product_id', $l->catalog_product_id)->sum('qty_change'));
            $short = round($amount - $taken, 2);

            $key = $l->branch_id . ':' . $l->catalog_product_id;
            $stock = Stock::where('branch_id', $l->branch_id)->where('catalog_product_id', $l->catalog_product_id)
                ->where('order_line_id', 0)->first();
            $running[$key] ??= (float) ($stock?->quantity ?? 0);
            $before = $running[$key];
            $running[$key] = round($before - max($short, 0), 2);
            $totalShort += max($short, 0);

            if ($apply) {
                DB::transaction(function () use ($l, $amount, $short, $stock) {
                    DB::table('sale_lines')->where('id', $l->line_id)->update(['qty' => $amount, 'rate' => 1]);
                    if ($short > 0 && $stock) {
                        $stock->decrement('quantity', $short);
                        StockMovement::create([
                            'branch_id' => $l->branch_id,
                            'catalog_product_id' => $l->catalog_product_id,
                            'type' => 'sale',
                            'qty_change' => -$short,
                            'balance_after' => $stock->fresh()->quantity,
                            'ref_type' => 'sale',
                            'ref_id' => $l->invoice_id,
                            'note' => 'Cash stock correction: bill was typed as 1 x amount (2026-11-21)',
                            'moved_on' => $l->date,
                        ]);
                    }
                });
            }

            $rows[] = [$l->invoice_no, (string) $l->date, $branches[$l->branch_id] ?? $l->branch_id,
                number_format($amount, 2), number_format($taken, 2), number_format($short, 2),
                number_format($before, 2), number_format($running[$key], 2) . ($running[$key] < 0 ? '  ← below zero' : '')];
        }

        $this->info(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $lines->count() . ' bill(s) with a cash line typed as 1 × amount, ₹'
            . number_format($totalShort, 2) . ' not deducted');
        $this->table(['Bill', 'Date', 'Branch', 'Bill amount ₹', 'Deducted ₹', 'To deduct ₹', 'Cash stock before ₹', 'Cash stock after ₹'], $rows);
        if (! $apply && $lines->isNotEmpty()) {
            $this->line('Run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
