<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Board complaint 2026-11-25: "stock varies" — audit of the whole restock chain
 *
 *   branch redeems a QR (stock OUT) → branch raises the restock order → Head Office approves
 *   (stock back IN)
 *
 * READ ONLY: it never writes to the database. It prints eight checks; with --csv it also writes
 * the detail lists to storage/app/stock-audit/ for Excel.
 *
 *   php artisan stock:audit-restock
 *   php artisan stock:audit-restock --csv
 *   php artisan stock:audit-restock --branch=59 --csv     one branch only (branch id)
 */
class StockAuditRestock extends Command
{
    use Concerns\BranchCsv;

    protected $signature = 'stock:audit-restock {--csv : also write the detail lists as CSV files}
        {--branch= : audit one branch only (branch id)}';

    protected $description = 'Read-only audit: redemption → restock order → approval → stock (nothing is changed)';

    /** @var array<string, array{0: string[], 1: array<int, array>}> */
    protected array $files = [];

    public function handle(): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $this->loadBranches();
        $branches = collect($this->branchRows);
        $products = DB::table('catalog_products')->get(['id', 'code', 'name', 'material'])->keyBy('id');
        $bName = fn ($id) => $this->bLabel($id);
        $pName = function ($id) use ($products) {
            $p = $products[$id] ?? null;
            if (! $p) {
                return 'Product #' . $id;
            }
            $name = json_decode((string) $p->name, true);

            return trim($p->code . ' ' . (is_array($name) ? ($name['en'] ?? reset($name)) : $p->name));
        };
        $hq = $branches->where('level', 'hq')->keys()->all();

        $this->line('Stock / restock audit — ' . now()->format('d M Y H:i') . ' — database ' . DB::connection()->getDatabaseName()
            . ($branchId ? ' — branch ' . $bName($branchId) : ' — all branches'));
        $this->line('READ ONLY: nothing is changed.');
        $this->newLine();
        $problems = 0;

        // ── 1. stock row = balance of its last movement ──
        $last = DB::table('stock_movements')->selectRaw('branch_id, catalog_product_id, max(id) id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->groupBy('branch_id', 'catalog_product_id')->pluck('id');
        $balance = [];
        foreach ($last->chunk(2000) as $ids) {
            foreach (DB::table('stock_movements')->whereIn('id', $ids)->get(['branch_id', 'catalog_product_id', 'balance_after', 'moved_on']) as $m) {
                $balance[$m->branch_id . '|' . $m->catalog_product_id] = $m;
            }
        }
        $stockRows = DB::table('stock')->where('order_line_id', 0)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['branch_id', 'catalog_product_id', 'quantity']);
        $gap = [];
        foreach ($stockRows as $s) {
            $m = $balance[$s->branch_id . '|' . $s->catalog_product_id] ?? null;
            if ($m && abs((float) $s->quantity - (float) $m->balance_after) > 0.001) {
                $gap[] = [$bName($s->branch_id), $pName($s->catalog_product_id), (float) $s->quantity, (float) $m->balance_after,
                    round((float) $s->quantity - (float) $m->balance_after, 4), $m->moved_on];
            }
        }
        $problems += $this->check('1. Stock on hand = balance of the last stock movement', count($gap),
            ['Branch', 'Product', 'Stock now', 'Last movement balance', 'Difference', 'Last moved on'], $gap, 'stock-vs-ledger');

        // ── order requests + their stock movements ──
        $orders = DB::table('branch_order_requests')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'request_no', 'source', 'status', 'branch_id', 'redemption_invoice_id', 'created_at', 'approved_at', 'grand_total'])->keyBy('id');
        $lineCount = DB::table('branch_order_lines')->whereNotNull('catalog_product_id')->selectRaw('order_request_id, count(*) n')
            ->groupBy('order_request_id')->pluck('n', 'order_request_id');
        // per order + product: pieces the buyer got (purchase + undo adjustments at the buyer's branch)
        $in = [];
        foreach (DB::table('stock_movements')->whereIn('ref_type', ['branch_order', 'branch_order_undo'])
            ->selectRaw('ref_id, branch_id, catalog_product_id, sum(qty_change) q')->groupBy('ref_id', 'branch_id', 'catalog_product_id')->get() as $m) {
            $o = $orders[$m->ref_id] ?? null;
            if ($o && (int) $o->branch_id === (int) $m->branch_id) {
                $in[$m->ref_id] ??= [];
                $in[$m->ref_id][$m->catalog_product_id] = round(($in[$m->ref_id][$m->catalog_product_id] ?? 0) + (float) $m->q, 4);
            }
        }

        // ── 2. approved orders that never moved stock ──
        $noMove = $orders->filter(fn ($o) => in_array($o->status, ['approved', 'delivered', 'billed'], true) && $o->source !== 'customize'
            && ($lineCount[$o->id] ?? 0) > 0 && ! isset($in[$o->id]))
            ->map(fn ($o) => [$o->request_no, $o->source, $bName($o->branch_id), $o->approved_at, (float) $o->grand_total])->values()->all();
        $problems += $this->check('2. Approved orders that added NO stock to the branch', count($noMove),
            ['Order', 'Type', 'Branch', 'Approved at', 'Order value'], $noMove, 'approved-without-stock');

        // ── 3. orders that are NOT approved but left stock in the branch ──
        $leftIn = $orders->filter(fn ($o) => in_array($o->status, ['pending', 'rejected', 'cancelled'], true)
            && abs(array_sum($in[$o->id] ?? [])) > 0.001)
            ->map(fn ($o) => [$o->request_no, $o->source, $o->status, $bName($o->branch_id), round(array_sum($in[$o->id]), 4)])->values()->all();
        $problems += $this->check('3. Pending / rejected / cancelled orders that still hold stock in the branch', count($leftIn),
            ['Order', 'Type', 'Status', 'Branch', 'Pieces left in'], $leftIn, 'not-approved-with-stock');

        // ── redemptions: pieces taken out, per invoice + product ──
        $out = [];
        foreach (DB::table('stock_movements')->where('ref_type', 'redemption')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('ref_id, catalog_product_id, sum(-qty_change) q')->groupBy('ref_id', 'catalog_product_id')->get() as $m) {
            $out[$m->ref_id] ??= [];
            $out[$m->ref_id][$m->catalog_product_id] = round((float) $m->q, 4);
        }
        // a cancelled redemption gave its pieces back itself
        foreach (DB::table('stock_movements')->where('ref_type', 'redemption_cancel')->selectRaw('ref_id, sum(qty_change) q')->groupBy('ref_id')->get() as $m) {
            if (isset($out[$m->ref_id]) && (float) $m->q >= array_sum($out[$m->ref_id]) - 0.001) {
                unset($out[$m->ref_id]);
            }
        }
        $invoices = DB::table('redemption_invoices')->whereNull('deleted_at')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'invoice_no', 'invoice_date', 'branch_id', 'grand_total'])->keyBy('id');
        $liveOrderOf = $orders->filter(fn ($o) => $o->redemption_invoice_id && in_array($o->status, ['pending', 'approved', 'delivered', 'billed'], true))
            ->keyBy('redemption_invoice_id');

        // ── 4. approved restock: pieces back = pieces taken ──
        $mismatch = [];
        $approvedNew = 0;
        $oldRestock = [];
        foreach ($orders as $o) {
            if (! $o->redemption_invoice_id || $o->status !== 'approved') {
                continue;
            }
            $got = $in[$o->id] ?? [];
            if (! isset($out[$o->redemption_invoice_id])) {
                // the pieces left in the OLD system (before go-live) — shown separately, check 7
                $oldRestock[] = [$o->request_no, $o->source, $bName($o->branch_id), $invoices[$o->redemption_invoice_id]->invoice_no ?? '?',
                    $o->approved_at, round(array_sum($got), 4)];

                continue;
            }
            $approvedNew++;
            $took = $out[$o->redemption_invoice_id];
            foreach (array_unique(array_merge(array_keys($took), array_keys($got))) as $cp) {
                if (abs(($took[$cp] ?? 0) - ($got[$cp] ?? 0)) > 0.001) {
                    $mismatch[] = [$o->request_no, $bName($o->branch_id), $invoices[$o->redemption_invoice_id]->invoice_no ?? '?', $pName($cp),
                        $took[$cp] ?? 0, $got[$cp] ?? 0, round(($got[$cp] ?? 0) - ($took[$cp] ?? 0), 4)];
                }
            }
        }
        $problems += $this->check('4. Approved restock orders (' . $approvedNew . ' checked): pieces put back ≠ pieces redeemed', count($mismatch),
            ['Order', 'Branch', 'Redeem invoice', 'Product', 'Redeemed (out)', 'Restocked (in)', 'Difference'], $mismatch, 'restock-mismatch');

        // ── 5. redemptions with stock taken and NO restock order raised ──
        $noOrder = [];
        $perBranch = [];
        foreach ($out as $invId => $took) {
            $inv = $invoices[$invId] ?? null;
            if (! $inv || isset($liveOrderOf[$invId]) || in_array($inv->branch_id, $hq, false)) {
                continue;   // Head Office redeems from its own stock — it has nobody to order from
            }
            $pieces = round(array_sum($took), 4);
            $noOrder[] = [$bName($inv->branch_id), $inv->invoice_no, $inv->invoice_date, $pieces, (float) $inv->grand_total,
                implode('; ', array_map(fn ($cp, $q) => $pName($cp) . ' × ' . $q, array_keys($took), $took))];
            $perBranch[$inv->branch_id] ??= [0, 0.0, $inv->invoice_date];
            $perBranch[$inv->branch_id][0]++;
            $perBranch[$inv->branch_id][1] += $pieces;
            $perBranch[$inv->branch_id][2] = min($perBranch[$inv->branch_id][2], $inv->invoice_date);
        }
        usort($noOrder, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);
        $summary = collect($perBranch)->map(fn ($v, $id) => [$bName($id), $v[0], round($v[1], 2), $v[2]])->sortByDesc(fn ($r) => $r[2])->values()->all();
        $problems += $this->check('5. Dealer redemptions with stock taken and NO restock order raised (' . count($noOrder) . ' invoices, '
            . round(array_sum(array_column($noOrder, 3)), 2) . ' pieces)', count($noOrder),
            ['Branch', 'Invoices', 'Pieces short', 'Oldest invoice'], $summary, null, 25);
        $this->files['redemptions-without-restock-order'] = [['Branch', 'Redeem invoice', 'Date', 'Pieces', 'Invoice value', 'Items'], $noOrder];

        // ── 6. restock orders still waiting for approval ──
        $pending = $orders->filter(fn ($o) => $o->status === 'pending' && in_array($o->source, ['redemption', 'legacy'], true));
        $pendRows = $pending->map(fn ($o) => [$o->request_no, $o->source === 'legacy' ? 'Old system (LEG-)' : 'Redemption', $bName($o->branch_id),
            $o->redemption_invoice_id ? ($invoices[$o->redemption_invoice_id]->invoice_no ?? '') : '', $o->created_at,
            $o->redemption_invoice_id ? round(array_sum($out[$o->redemption_invoice_id] ?? []), 4) : 0, (float) $o->grand_total])->values()->all();
        $pendSummary = $pending->groupBy(fn ($o) => $o->source === 'legacy' ? 'Old system (LEG-)' : 'Redemption')
            ->map(fn (Collection $g, $k) => [$k, $g->count(), round($g->sum(fn ($o) => array_sum($out[$o->redemption_invoice_id] ?? [])), 2),
                substr((string) $g->min('created_at'), 0, 10)])->values()->all();
        $problems += $this->check('6. Restock orders raised but still WAITING for Head Office approval', count($pendRows),
            ['Type', 'Orders', 'Pieces waiting', 'Oldest order'], $pendSummary, null);
        $this->files['restock-orders-pending'] = [['Order', 'Type', 'Branch', 'Redeem invoice', 'Raised at', 'Pieces waiting', 'Order value'], $pendRows];

        // ── 7. approved orders that restocked an OLD-system redemption ──
        $this->check('7. For information: approved orders that restocked an OLD-system redemption (stock had left in the old system)',
            count($oldRestock), ['Order', 'Type', 'Branch', 'Redeem invoice', 'Approved at', 'Pieces put in'],
            array_slice($oldRestock, 0, 0), 'old-redemption-restocked', 0, $oldRestock);
        if ($oldRestock) {
            $this->line('   ' . round(array_sum(array_column($oldRestock, 5)), 2) . ' pieces put in by ' . count($oldRestock) . ' order(s).');
        }

        // ── 8. stock below zero ──
        $negative = DB::table('stock')->where('quantity', '<', -0.001)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('quantity')->get(['branch_id', 'catalog_product_id', 'quantity'])
            ->map(fn ($s) => [$bName($s->branch_id), $pName($s->catalog_product_id), $products[$s->catalog_product_id]->material ?? '', (float) $s->quantity])->all();
        $problems += $this->check('8. Stock below zero', count($negative), ['Branch', 'Product', 'Material', 'Quantity'], $negative, 'negative-stock', 30);

        $this->newLine();
        $this->line('Checks 1–4 are the SYSTEM (a count above 0 there is a fault to report). Checks 5–6 are work waiting on people:');
        $this->line('  5 = the branch has not raised its restock order (Redemption Invoices → Raise restock order);');
        $this->line('  6 = Head Office has not approved it yet (Order Requests).');

        if ($this->option('csv')) {
            $dir = storage_path('app/stock-audit');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $stamp = now()->format('Ymd-His');
            $this->newLine();
            foreach ($this->files as $name => [$head, $rows]) {
                if (! $rows) {
                    continue;
                }
                $this->writeCsv($dir . DIRECTORY_SEPARATOR . $stamp . '-' . $name . '.csv', $head, $rows);
            }
        } else {
            $this->line('Add --csv to write the full lists to storage/app/stock-audit/.');
        }

        return self::SUCCESS;
    }

    /**
     * Print one check; returns its count. $file = name of the CSV holding $rows (or $all).
     */
    protected function check(string $title, int $count, array $head, array $rows, ?string $file, int $show = 15, ?array $all = null): int
    {
        $this->line(($count === 0 ? '<info>OK   </info>' : '<comment>' . str_pad((string) $count, 5) . '</comment>') . $title);
        if ($rows && $show > 0) {
            $this->table($head, array_slice($rows, 0, $show));
            if (count($rows) > $show) {
                $this->line('   … ' . (count($rows) - $show) . ' more — use --csv for the full list.');
            }
        }
        if ($file) {
            $this->files[$file] = [$head, $all ?? $rows];
        }

        return $count;
    }
}
