<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Major complaint 2026-11-25: a bill could sell MORE than the branch held (INV-HQ-0604: cash
 * stock ₹2.62 Cr, billed ₹112.62 Cr, stock left at −₹110 Cr) — nothing on the server checked it.
 * The gate is in now (SalesService::assertStockCovers, renewals, supplier shipping). This lists
 * who used the gap before it closed.
 *
 * READ ONLY — it never writes to the database.
 *
 *   A. every stock OUT that took a stock row below zero: the bill / renewal / other entry, the
 *      branch, the login that entered it, what was held and what was taken
 *   B. bills that sold an item the branch had NO stock row for (nothing was deducted at all)
 *   C. the stock rows that are below zero today
 *
 *   php artisan stock:audit-overbilled
 *   php artisan stock:audit-overbilled --csv              full lists → storage/app/stock-audit/
 *   php artisan stock:audit-overbilled --from=2026-11-01  entries on or after this day
 */
class StockAuditOverbilled extends Command
{
    use Concerns\BranchCsv;

    protected $signature = 'stock:audit-overbilled {--csv : also write the full lists as CSV files}
        {--from=2026-11-01 : entries on or after this day} {--branch= : one branch only (branch id)}';

    protected $description = 'Read-only: who billed more than the branch stock held (cash, gold, silver) — nothing is changed';

    protected const KINDS = [
        'sale' => 'Sales bill', 'rd_entry' => 'Gold Saving renewal', 'redemption' => 'Redemption', 'branch_order' => 'Order request',
        'force_delete' => 'Force close charge', 'hq_adjustment' => 'HQ adjustment', 'stock_return' => 'Stock return', 'sale_cancel' => 'Bill cancelled',
    ];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $this->loadBranches();
        $products = DB::table('catalog_products')->get(['id', 'code', 'name', 'material'])->keyBy('id');
        $users = DB::table('users')->get(['id', 'name', 'email', 'member_code', 'branch_id'])->keyBy('id');
        $bName = fn ($id) => $this->bLabel($id);
        $pName = function ($id) use ($products) {
            $p = $products[$id] ?? null;
            $name = $p ? json_decode((string) $p->name, true) : null;

            return $p ? trim($p->code . ' ' . (is_array($name) ? ($name['en'] ?? reset($name)) : $p->name)) : 'Product #' . $id;
        };
        $uName = fn ($id) => ($u = $users[$id] ?? null)
            ? trim($u->name . ($u->member_code ? ' · ' . $u->member_code : '') . ' · ' . $u->email . ($u->branch_id ? '' : ' (Head Office login)')) : ($id ? 'User #' . $id : 'system');

        $this->line('Over-billing audit — ' . now()->format('d M Y H:i') . ' — database ' . DB::connection()->getDatabaseName() . ' — from ' . $from);
        $this->line('READ ONLY: nothing is changed.');
        $this->newLine();

        // ── A. stock OUT that left the row below zero ──
        $moves = DB::table('stock_movements')->where('qty_change', '<', 0)->where('balance_after', '<', -0.005)
            ->where('moved_on', '>=', $from)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('id')->get();
        $saleIds = $moves->where('ref_type', 'sale')->pluck('ref_id')->filter()->unique();
        $invoices = DB::table('sales_invoices')->whereIn('id', $saleIds)->get(['id', 'invoice_no', 'status', 'grand_total'])->keyBy('id');
        $rdIds = $moves->where('ref_type', 'rd_entry')->pluck('ref_id')->filter()->unique();
        $rd = DB::table('rd_entries as e')->leftJoin('members as m', 'm.id', '=', 'e.member_id')->whereIn('e.id', $rdIds)
            ->get(['e.id', 'm.member_code', 'e.due_count'])->keyBy('id');
        $rows = [];
        foreach ($moves as $m) {
            $taken = -(float) $m->qty_change;
            $before = round((float) $m->balance_after + $taken, 4);
            $over = round($taken - max(0.0, $before), 4);
            $ref = match ($m->ref_type) {
                'sale' => ($invoices[$m->ref_id]->invoice_no ?? 'bill #' . $m->ref_id) . (($invoices[$m->ref_id]->status ?? null) === 'cancelled' ? ' (CANCELLED)' : ''),
                'rd_entry' => 'renewal #' . $m->ref_id . (isset($rd[$m->ref_id]) ? ' · ' . $rd[$m->ref_id]->member_code . ' due ' . $rd[$m->ref_id]->due_count : ''),
                default => trim(($m->ref_type ?: '') . ' #' . $m->ref_id . ' ' . ($m->note ?: '')),
            };
            $by = $m->created_by;
            $rows[] = [
                'date' => $m->moved_on, 'kind' => self::KINDS[$m->ref_type] ?? ($m->ref_type ?: $m->type), 'ref' => $ref,
                'branch' => $bName($m->branch_id), 'product' => $pName($m->catalog_product_id),
                'material' => $products[$m->catalog_product_id]->material ?? '', 'held' => $before, 'taken' => $taken,
                'over' => $over, 'after' => (float) $m->balance_after, 'by' => $uName($by), 'by_id' => $by,
            ];
        }
        $this->line('<comment>' . count($rows) . '</comment>  A. Stock taken OUT beyond what the branch held (the row went, or went further, below zero)');
        if ($rows) {
            $this->table(['Kind', 'Entries', 'Cash over (₹)', 'Gold / silver over (pcs)'], collect($rows)->groupBy('kind')->map(fn ($g, $k) => [
                $k, $g->count(), number_format($g->where('material', 'cash')->sum('over'), 2), round($g->where('material', '!=', 'cash')->sum('over'), 3),
            ])->values()->all());
            $this->line('   By login — who entered them:');
            $this->table(['Login', 'Entries', 'Cash over (₹)', 'Gold / silver over (pcs)', 'First', 'Last'], collect($rows)->groupBy('by')->map(fn ($g, $k) => [
                $k, $g->count(), number_format($g->where('material', 'cash')->sum('over'), 2), round($g->where('material', '!=', 'cash')->sum('over'), 3),
                $g->min('date'), $g->max('date'),
            ])->sortByDesc(fn ($r) => (float) str_replace(',', '', $r[2]))->values()->take(30)->all());
            $this->line('   Largest entries:');
            $this->table(['Date', 'Kind', 'Entry', 'Branch', 'Item', 'Held', 'Taken', 'Over by', 'Entered by'],
                collect($rows)->sortByDesc(fn ($r) => $r['material'] === 'cash' ? $r['over'] : $r['over'] * 1e7)->take(25)
                    ->map(fn ($r) => [$r['date'], $r['kind'], $r['ref'], $r['branch'], $r['product'], $this->num($r['held']), $this->num($r['taken']), $this->num($r['over']), $r['by']])->values()->all());
        }

        // ── B. bills that sold an item with no stock row (nothing deducted) ──
        $firstRef = (int) DB::table('stock_movements')->where('ref_type', 'sale')->whereNotNull('ref_id')->min('ref_id');
        $noDeduct = [];
        if ($firstRef) {
            $moved = DB::table('stock_movements')->where('ref_type', 'sale')->whereNotNull('ref_id')
                ->selectRaw('ref_id, catalog_product_id')->distinct()->get()->map(fn ($m) => $m->ref_id . '|' . $m->catalog_product_id)->flip();
            $lines = DB::table('sale_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.invoice_id')
                ->where('i.id', '>=', $firstRef)->where('i.created_at', '>=', $from)->whereNotNull('l.catalog_product_id')
                ->when($branchId, fn ($q) => $q->where('i.branch_id', $branchId))
                ->get(['i.id', 'i.invoice_no', 'i.date', 'i.status', 'i.branch_id', 'l.catalog_product_id', 'l.line_total']);
            foreach ($lines as $l) {
                if (! isset($moved[$l->id . '|' . $l->catalog_product_id])) {
                    $noDeduct[] = [$l->date, $l->invoice_no . ($l->status === 'cancelled' ? ' (CANCELLED)' : ''), $bName($l->branch_id), $pName($l->catalog_product_id),
                        number_format((float) $l->line_total, 2)];
                }
            }
        }
        $this->newLine();
        $this->line(($noDeduct ? '<comment>' . count($noDeduct) . '</comment>' : '<info>OK</info>') . '  B. Bill lines that deducted NO stock (the branch had no stock row for the item)');
        if ($noDeduct) {
            $this->table(['Date', 'Bill', 'Branch (the bill was entered at)', 'Item', 'Line value'], array_slice($noDeduct, 0, 25));
            if (count($noDeduct) > 25) {
                $this->line('   … ' . (count($noDeduct) - 25) . ' more — use --csv.');
            }
        }

        // ── C. below zero today ──
        $negative = DB::table('stock')->where('quantity', '<', -0.005)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('quantity')->get(['branch_id', 'catalog_product_id', 'quantity'])
            ->map(fn ($s) => [$bName($s->branch_id), $pName($s->catalog_product_id), $products[$s->catalog_product_id]->material ?? '', $this->num((float) $s->quantity)])->all();
        $this->newLine();
        $this->line(($negative ? '<comment>' . count($negative) . '</comment>' : '<info>OK</info>') . '  C. Stock rows below zero TODAY');
        if ($negative) {
            $this->table(['Branch', 'Item', 'Material', 'Quantity'], array_slice($negative, 0, 40));
        }

        if ($this->option('csv')) {
            $dir = storage_path('app/stock-audit');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $stamp = now()->format('Ymd-His');
            $this->newLine();
            $this->writeCsv($dir . DIRECTORY_SEPARATOR . $stamp . '-overbilled-entries.csv',
                ['Date', 'Kind', 'Entry', 'Branch', 'Item', 'Material', 'Held before', 'Taken', 'Over by', 'Stock after', 'Entered by'],
                array_map(fn ($r) => [$r['date'], $r['kind'], $r['ref'], $r['branch'], $r['product'], $r['material'], $r['held'], $r['taken'], $r['over'], $r['after'], $r['by']], $rows));
            $this->writeCsv($dir . DIRECTORY_SEPARATOR . $stamp . '-bills-without-deduction.csv', ['Date', 'Bill', 'Branch', 'Item', 'Line value'], $noDeduct);
            $this->writeCsv($dir . DIRECTORY_SEPARATOR . $stamp . '-negative-stock-today.csv', ['Branch', 'Item', 'Material', 'Quantity'], $negative);
        } else {
            $this->newLine();
            $this->line('Add --csv to write the full lists to storage/app/stock-audit/.');
        }

        return self::SUCCESS;
    }

    protected function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4), '0'), '.');
    }
}
