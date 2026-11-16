<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-16: old-system (LEG-) orders approved BEFORE the piece-count fix were stocked as
 * if their lines were grams — "100 mg Gold" × 6 put 60 coins into the branch, "8 g Gold" × 1 put
 * 0.125 of a coin. This lists every approved old-system order with what it SHOULD have added
 * against what the stock ledger shows for it. What it should have added = the pieces of the
 * redemption invoice it restores (its valid cart) when the order is linked to one, else the
 * order's own lines. It also flags:
 *
 *   - a restock approved twice for the same redemption invoice, or approved here although the
 *     old system had already approved one (stock given back twice);
 *   - an order approved while it sat on a branch that did not place it (stock in the wrong branch).
 *
 *   php artisan legacy:audit-approved-orders                       report only
 *   php artisan legacy:audit-approved-orders --apply               post the piece-count corrections
 *   php artisan legacy:audit-approved-orders --reverse-duplicates  report, showing the double restocks as reversals
 *   php artisan legacy:audit-approved-orders --reverse-duplicates --apply
 *        also take the second restock back: everything that order added is removed again and
 *        the order is closed as "cancelled" with the reason (the one linked to the invoice is kept)
 *
 * Every change is a stock "adjustment" movement with a note, against the order. Idempotent.
 */
class LegacyAuditApprovedOrders extends Command
{
    protected $signature = 'legacy:audit-approved-orders {--apply : write (default = report only)} {--reverse-duplicates : take back the stock of a restock that was given twice} {--connection=legacy : legacy DB connection}';

    protected $description = 'Audit old-system orders already approved: stock added vs the pieces due, double restocks, wrong branch';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $apply = (bool) $this->option('apply');
        $reverse = (bool) $this->option('reverse-duplicates');
        $legacy = DB::connection((string) $this->option('connection'));

        $orders = DB::table('branch_order_requests')->where('source', 'legacy')
            ->whereIn('status', ['approved', 'in_transit', 'delivered', 'billed'])->orderBy('id')
            ->get(['id', 'request_no', 'source_ref', 'branch_id', 'status', 'grand_total', 'approved_at', 'approved_by', 'redemption_invoice_id']);
        if ($orders->isEmpty()) {
            $this->info('No old-system order has been approved here.');

            return self::SUCCESS;
        }

        $lines = DB::table('branch_order_lines')->whereIn('order_request_id', $orders->pluck('id'))
            ->get(['order_request_id', 'catalog_product_id', 'material', 'weight'])->groupBy('order_request_id');
        $moves = DB::table('stock_movements')->where('ref_type', 'branch_order')->whereIn('ref_id', $orders->pluck('id'))
            ->get(['ref_id', 'branch_id', 'catalog_product_id', 'qty_change'])->groupBy('ref_id');
        $invoices = DB::table('redemption_invoices')->whereIn('id', $orders->pluck('redemption_invoice_id')->filter())
            ->get(['id', 'invoice_no', 'taxable_total'])->keyBy('id');
        $invLines = DB::table('redemption_lines')->whereIn('redemption_invoice_id', $invoices->keys())->orderBy('id')->get()->groupBy('redemption_invoice_id');
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();
        $productNames = DB::table('catalog_products')->pluck('name', 'id')->map(fn ($n) => json_decode((string) $n, true)['en'] ?? (string) $n)->all();
        $byBrid = DB::table('branches')->whereNotNull('legacy_brid')->pluck('id', 'legacy_brid')->all();
        $users = DB::table('users')->pluck('name', 'id')->all();
        $old = $legacy->table('tbl_brorderrequest')->whereIn('transid', $orders->pluck('source_ref'))
            ->get(['transid', 'brid', 'stockflag', 'paymode'])->keyBy('transid');
        $approvedInLegacy = $legacy->table('tbl_brorderrequest')->where('status', 'APPROVED')->where('paymode', 'REDEEM')
            ->get(['transid', 'stockflag', 'doorder'])->keyBy(fn ($r) => (string) $r->stockflag)->all();

        // restocks of the same redemption invoice approved more than once here
        $perInvoice = [];
        foreach ($orders as $o) {
            $src = $old[(int) $o->source_ref] ?? null;
            if ($src && strtoupper((string) $src->paymode) === 'REDEEM') {
                $perInvoice[(string) $src->stockflag][] = $o;
            }
        }

        // ---- flags + which orders are a second restock
        $flags = [];
        $toReverse = [];   // order id => reason
        foreach ($orders as $o) {
            $src = $old[(int) $o->source_ref] ?? null;
            $want = $src ? ($byBrid[(int) $src->brid] ?? null) : null;
            $why = [];
            if ($want && (int) $want !== (int) $o->branch_id) {
                $why[] = 'stock went to ' . ($branchNames[$o->branch_id] ?? '?') . ' #' . $o->branch_id . ' — the order belongs to ' . ($branchNames[$want] ?? '?') . ' #' . $want;
            }
            if ($src && strtoupper((string) $src->paymode) === 'REDEEM') {
                $flag = (string) $src->stockflag;
                $group = $perInvoice[$flag];
                if ($done = $approvedInLegacy[$flag] ?? null) {
                    $why[] = 'redemption invoice ' . $flag . ' was already restocked in the old system (order ' . $done->transid . ', ' . $done->doorder . ') — restocked twice';
                    $toReverse[$o->id] = 'redemption invoice ' . $flag . ' was already restocked in the old system (order ' . $done->transid . ', approved, ' . $done->doorder . ')';
                } elseif (count($group) > 1) {
                    // keep the one linked to the invoice, else the newest; the others are the second restock
                    $keep = collect($group)->sortByDesc(fn ($x) => [$x->redemption_invoice_id ? 1 : 0, (int) $x->source_ref])->first();
                    $why[] = 'redemption invoice ' . $flag . ' approved ' . count($group) . ' times here (' . implode(', ', array_map(fn ($x) => $x->request_no, $group)) . ')'
                        . ((int) $keep->id === (int) $o->id ? ' — this one is kept' : ' — second restock');
                    if ((int) $keep->id !== (int) $o->id) {
                        $toReverse[$o->id] = 'redemption invoice ' . $flag . ' was restocked twice — ' . $keep->request_no . ' is the restock that stands';
                    }
                }
            }
            if ($why) {
                $flags[] = [$o->request_no, $branchNames[$o->branch_id] ?? '?', number_format((float) $o->grand_total, 2), substr((string) $o->approved_at, 0, 16), $users[$o->approved_by] ?? '—', implode('; ', $why)];
            }
        }

        $stockNow = function (int $branchId, int $productId): float {
            return (float) DB::table('stock')->where('branch_id', $branchId)->where('catalog_product_id', $productId)->value('quantity');
        };
        $post = function (object $o, int $productId, float $delta, string $note): void {
            DB::transaction(function () use ($o, $productId, $delta, $note) {
                $stock = Stock::firstOrCreate(['branch_id' => $o->branch_id, 'catalog_product_id' => $productId], ['quantity' => 0]);
                $stock->increment('quantity', $delta);
                StockMovement::create([
                    'branch_id' => $o->branch_id, 'catalog_product_id' => $productId, 'type' => 'adjustment',
                    'qty_change' => $delta, 'balance_after' => $stock->fresh()->quantity,
                    'ref_type' => 'branch_order', 'ref_id' => $o->id, 'note' => mb_substr($note, 0, 250),
                    'moved_on' => Carbon::now()->toDateString(),
                ]);
            });
        };
        $running = [];   // "branch|product" => stock after the rows printed so far
        $after = function (object $o, int $productId, float $delta) use (&$running, $stockNow): array {
            $key = $o->branch_id . '|' . $productId;
            $before = $running[$key] ?? $stockNow((int) $o->branch_id, $productId);
            $running[$key] = round($before + $delta, 4);

            return [$before, $running[$key]];
        };

        // ---- A. second restocks: take back everything the order added
        $reverseRows = [];
        if ($reverse) {
            foreach ($orders as $o) {
                if (! isset($toReverse[$o->id])) {
                    continue;
                }
                $net = [];
                foreach (($moves[$o->id] ?? collect())->where('branch_id', $o->branch_id) as $m) {
                    $net[(int) $m->catalog_product_id] = ($net[(int) $m->catalog_product_id] ?? 0) + (float) $m->qty_change;
                }
                foreach ($net as $productId => $added) {
                    if (abs($added) < 0.0001) {
                        continue;
                    }
                    [$before, $now] = $after($o, $productId, -$added);
                    $reverseRows[] = [$o->request_no, $branchNames[$o->branch_id] ?? '?', $productNames[$productId] ?? '#' . $productId,
                        $this->n($added), '-' . $this->n($added), $this->n($before) . ' → ' . $this->n($now) . ($now < -0.0001 ? '  NEGATIVE' : '')];
                    if ($apply) {
                        $post($o, $productId, -$added, 'Reversal — ' . $o->request_no . ': ' . $toReverse[$o->id]);
                    }
                }
                if ($apply) {
                    DB::table('branch_order_requests')->where('id', $o->id)->update([
                        'status' => 'cancelled', 'cancelled_at' => now(), 'redemption_invoice_id' => null,
                        'decision_note' => mb_substr('Approved in error, stock taken back — ' . $toReverse[$o->id], 0, 500), 'updated_at' => now(),
                    ]);
                }
            }
        }

        // ---- B. piece-count corrections on the orders that stand
        $rows = [];
        foreach ($orders as $o) {
            if ($reverse && isset($toReverse[$o->id])) {
                continue;
            }
            $expected = [];
            $basis = 'order lines';
            $inv = $o->redemption_invoice_id ? ($invoices[$o->redemption_invoice_id] ?? null) : null;
            $valid = $inv ? LegacyLinkRestockOrders::validLines(($invLines[$inv->id] ?? collect())->all(), (float) $inv->taxable_total) : null;
            if ($valid) {
                $basis = 'invoice ' . $inv->invoice_no;
                foreach ($valid as $l) {
                    $expected[(int) $l->catalog_product_id] = ($expected[(int) $l->catalog_product_id] ?? 0) + LegacyLinkRestockOrders::pieces($l);
                }
            } else {
                foreach ($lines[$o->id] ?? [] as $l) {
                    if ($l->catalog_product_id && $l->material !== 'cash') {
                        $expected[(int) $l->catalog_product_id] = ($expected[(int) $l->catalog_product_id] ?? 0) + (float) $l->weight;
                    }
                }
            }
            $movedBy = [];
            foreach (($moves[$o->id] ?? collect())->where('branch_id', $o->branch_id) as $m) {
                $movedBy[(int) $m->catalog_product_id] = ($movedBy[(int) $m->catalog_product_id] ?? 0) + (float) $m->qty_change;
            }
            foreach (array_unique(array_merge(array_keys($expected), array_keys($movedBy))) as $productId) {
                if (($productNames[$productId] ?? '') === 'CASH') {
                    continue;
                }
                $pieces = (float) ($expected[$productId] ?? 0);
                $moved = (float) ($movedBy[$productId] ?? 0);
                $delta = round($pieces - $moved, 4);
                if (abs($delta) < 0.0001) {
                    continue;
                }
                [$before, $now] = $after($o, $productId, $delta);
                $rows[] = [$o->request_no, $branchNames[$o->branch_id] ?? '?', $productNames[$productId] ?? '#' . $productId, $basis,
                    $this->n($pieces), $this->n($moved), ($delta > 0 ? '+' : '') . $this->n($delta),
                    $this->n($before) . ' → ' . $this->n($now) . ($now < -0.0001 ? '  NEGATIVE' : '')];
                if ($apply) {
                    $post($o, $productId, $delta, sprintf('Correction — %s is due %s piece(s) (%s), %s were added at approval', $o->request_no, $this->n($pieces), $basis, $this->n($moved)));
                }
            }
        }

        $this->line('Old-system orders approved here: ' . $orders->count());
        if ($reverse) {
            $this->line('<comment>Second restocks taken back: ' . count(array_unique(array_column($reverseRows, 0))) . ' order(s)</comment>');
            if ($reverseRows) {
                $this->table(['Order', 'Branch', 'Item', 'It added', 'Reversal', 'Branch stock'], $reverseRows);
            }
        }
        $this->line('<comment>Stock added does not equal the pieces due: ' . count($rows) . ' line(s)</comment>');
        if ($rows) {
            $this->table(['Order', 'Branch', 'Item', 'Due as per', 'Due (pcs)', 'Added', 'Correction', 'Branch stock'], $rows);
        }
        $this->line('<comment>Flagged: ' . count($flags) . ' order(s)' . ($reverse ? '' : ' — not changed unless --reverse-duplicates is given') . '</comment>');
        if ($flags) {
            $this->table(['Order', 'Branch', '₹', 'Approved at', 'By', 'Why'], $flags);
        }
        // net effect per branch + item, and anything that would go below zero
        $net = [];
        foreach ($running as $key => $final) {
            [$branchId, $productId] = array_map('intval', explode('|', $key));
            $start = $stockNow($branchId, $productId);
            $shown = $apply ? $start : $final;   // after --apply the table already reads the new stock
            $net[] = [$branchNames[$branchId] ?? '?', $productNames[$productId] ?? '#' . $productId,
                $apply ? '—' : $this->n($start), $this->n($shown), $shown < -0.0001 ? 'NEGATIVE — already sold / redeemed' : ''];
        }
        usort($net, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->line('<comment>Stock per branch and item ' . ($apply ? 'after the corrections' : 'now → after') . '</comment>');
        $this->table(['Branch', 'Item', 'Now', 'After', ''], $net);
        $this->line($apply ? '<info>Applied.</info>' : '<comment>Report only — nothing written. Re-run with --apply to write it.</comment>');

        return self::SUCCESS;
    }

    protected function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }
}
