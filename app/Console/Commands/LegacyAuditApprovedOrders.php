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
 * (the line's pieces) against what the stock ledger shows for it, and flags:
 *
 *   - a restock approved twice for the same redemption invoice, or approved here although the
 *     old system had already approved one (stock given back twice) — decide by hand;
 *   - an order approved while it sat on a branch that did not place it (stock in the wrong branch).
 *
 *   php artisan legacy:audit-approved-orders            report only
 *   php artisan legacy:audit-approved-orders --apply    post the piece-count corrections
 *                                                       (stock + an "adjustment" movement per line; idempotent)
 *
 * Only the piece-count error is corrected by --apply; the flagged cases are never touched.
 */
class LegacyAuditApprovedOrders extends Command
{
    protected $signature = 'legacy:audit-approved-orders {--apply : post the piece-count corrections (default = report only)} {--connection=legacy : legacy DB connection}';

    protected $description = 'Audit old-system orders already approved: stock added vs the pieces ordered, double restocks, wrong branch';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $apply = (bool) $this->option('apply');
        $legacy = DB::connection((string) $this->option('connection'));

        $orders = DB::table('branch_order_requests')->where('source', 'legacy')
            ->whereIn('status', ['approved', 'in_transit', 'delivered', 'billed'])->orderBy('id')
            ->get(['id', 'request_no', 'source_ref', 'branch_id', 'status', 'grand_total', 'approved_at', 'approved_by']);
        if ($orders->isEmpty()) {
            $this->info('No old-system order has been approved here.');

            return self::SUCCESS;
        }

        $lines = DB::table('branch_order_lines')->whereIn('order_request_id', $orders->pluck('id'))
            ->get(['order_request_id', 'catalog_product_id', 'material', 'description', 'weight'])->groupBy('order_request_id');
        $moves = DB::table('stock_movements')->where('ref_type', 'branch_order')->whereIn('ref_id', $orders->pluck('id'))
            ->get(['ref_id', 'branch_id', 'catalog_product_id', 'qty_change'])->groupBy('ref_id');
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();
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
                $perInvoice[(string) $src->stockflag][] = $o->request_no;
            }
        }

        $rows = [];
        $flags = [];
        $corrections = 0;
        foreach ($orders as $o) {
            $src = $old[(int) $o->source_ref] ?? null;
            $want = $src ? ($byBrid[(int) $src->brid] ?? null) : null;
            $why = [];
            if ($want && (int) $want !== (int) $o->branch_id) {
                $why[] = 'stock went to ' . ($branchNames[$o->branch_id] ?? '?') . ' #' . $o->branch_id . ' — the order belongs to ' . ($branchNames[$want] ?? '?') . ' #' . $want;
            }
            if ($src && strtoupper((string) $src->paymode) === 'REDEEM') {
                if ($done = $approvedInLegacy[(string) $src->stockflag] ?? null) {
                    $why[] = 'redemption invoice ' . $src->stockflag . ' was already restocked in the old system (order ' . $done->transid . ', ' . $done->doorder . ') — restocked twice';
                }
                if (count($perInvoice[(string) $src->stockflag]) > 1) {
                    $why[] = 'redemption invoice ' . $src->stockflag . ' approved ' . count($perInvoice[(string) $src->stockflag]) . ' times here (' . implode(', ', $perInvoice[(string) $src->stockflag]) . ')';
                }
            }
            if ($why) {
                $flags[] = [$o->request_no, $branchNames[$o->branch_id] ?? '?', number_format((float) $o->grand_total, 2), substr((string) $o->approved_at, 0, 16), $users[$o->approved_by] ?? '—', implode('; ', $why)];
            }

            $expected = [];
            $label = [];
            foreach ($lines[$o->id] ?? [] as $l) {
                if (! $l->catalog_product_id || $l->material === 'cash') {
                    continue;
                }
                $expected[(int) $l->catalog_product_id] = ($expected[(int) $l->catalog_product_id] ?? 0) + (float) $l->weight;
                $label[(int) $l->catalog_product_id] = $l->description;
            }
            foreach ($expected as $productId => $pieces) {
                $moved = (float) ($moves[$o->id] ?? collect())->where('branch_id', $o->branch_id)->where('catalog_product_id', $productId)->sum('qty_change');
                $delta = round($pieces - $moved, 4);
                if (abs($delta) < 0.0001) {
                    continue;
                }
                $corrections++;
                $rows[] = [$o->request_no, $branchNames[$o->branch_id] ?? '?', substr((string) $o->approved_at, 0, 10), $label[$productId],
                    $this->n($pieces), $this->n($moved), ($delta > 0 ? '+' : '') . $this->n($delta)];
                if ($apply) {
                    DB::transaction(function () use ($o, $productId, $delta, $pieces, $moved) {
                        $stock = Stock::firstOrCreate(['branch_id' => $o->branch_id, 'catalog_product_id' => $productId], ['quantity' => 0]);
                        $stock->increment('quantity', $delta);
                        StockMovement::create([
                            'branch_id' => $o->branch_id, 'catalog_product_id' => $productId, 'type' => 'adjustment',
                            'qty_change' => $delta, 'balance_after' => $stock->fresh()->quantity,
                            'ref_type' => 'branch_order', 'ref_id' => $o->id,
                            'note' => sprintf('Correction — %s ordered %s piece(s), %s were added at approval (old-system lines count pieces)', $o->request_no, $this->n($pieces), $this->n($moved)),
                            'moved_on' => Carbon::now()->toDateString(),
                        ]);
                    });
                }
            }
        }

        $this->line('Old-system orders approved here: ' . $orders->count());
        $this->line('<comment>Stock added does not equal the pieces ordered: ' . $corrections . ' line(s)</comment>');
        if ($rows) {
            $this->table(['Order', 'Branch', 'Approved', 'Item', 'Ordered (pcs)', 'Added to stock', 'Correction'], $rows);
        }
        $this->line('<comment>To decide by hand (never changed by this command): ' . count($flags) . ' order(s)</comment>');
        if ($flags) {
            $this->table(['Order', 'Branch', '₹', 'Approved at', 'By', 'Why'], $flags);
        }
        $this->line($apply ? '<info>Piece-count corrections posted.</info>' : '<comment>Report only — nothing written. Re-run with --apply to post the piece-count corrections.</comment>');

        return self::SUCCESS;
    }

    protected function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }
}
