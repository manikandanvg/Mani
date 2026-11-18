<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Complaint 2026-11-18 (Lord's board): "after billing, renewal and redemption the stock stays
 * the same — cash, gold and silver". This lists every new-system bill line, Gold Saving
 * renewal and redemption that left NO stock movement behind, so the cause is read from the
 * data instead of guessed. READ-ONLY — it writes nothing but the CSV report.
 *
 *   php artisan stock:audit-deductions                    since 2026-11-01
 *   php artisan stock:audit-deductions --from=2026-11-10
 *
 * What should move stock:
 *   bill        every stock line of the bill (cash = ₹, gold / silver = pieces)  → movement sale / invoice id
 *   renewal     the collected amount, from the branch's cash stock               → movement rd_entry
 *   redemption  the items handed over, from the redeeming branch                 → movement redemption / invoice id
 *               EXCEPT a gold / silver PURCHASE scheme (G10): its metal already left the stock
 *               on the bill, so its redemption moves nothing — reported apart, not as a fault.
 */
class StockAuditDeductions extends Command
{
    protected $signature = 'stock:audit-deductions {--from=2026-11-01 : first date to check (new system go-live)} {--out= : CSV path (default storage/app/reports)}';

    protected $description = 'List bills, Gold Saving renewals and redemptions that did not reduce the branch stock (read-only)';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $rows = [];
        $sum = [];
        $branches = DB::table('branches')->pluck('name', 'id');
        $products = DB::table('catalog_products')->pluck('code', 'id');
        $add = function (string $kind, string $doc, $date, $branchId, string $item, float $qty, string $why) use (&$rows, $branches) {
            $rows[] = [$kind, $doc, (string) $date, (string) ($branches[$branchId] ?? $branchId), $item, $qty, $why];
        };

        // ── 1. bills ──
        $moved = [];
        foreach (DB::table('stock_movements')->where('ref_type', 'sale')->whereNotNull('ref_id')->get(['ref_id', 'catalog_product_id']) as $m) {
            $moved[$m->ref_id . ':' . $m->catalog_product_id] = true;
        }
        $bills = DB::table('sales_invoices')->where('invoice_no', 'like', 'INV-%')->where('date', '>=', $from)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))->get(['id', 'invoice_no', 'date', 'branch_id']);
        $lines = DB::table('sale_lines')->whereIn('invoice_id', $bills->pluck('id'))->get()->groupBy('invoice_id');
        $sum['Bills checked'] = $bills->count();
        $sum['Bill lines WITHOUT a stock movement'] = 0;
        foreach ($bills as $b) {
            foreach ($lines[$b->id] ?? [] as $l) {
                if (! $l->catalog_product_id) {
                    $sum['Bill lines WITHOUT a stock movement']++;
                    $add('bill', $b->invoice_no, $b->date, $b->branch_id, (string) $l->description, (float) $l->qty, 'line saved without a stock item');
                } elseif (! isset($moved[$b->id . ':' . $l->catalog_product_id])) {
                    $sum['Bill lines WITHOUT a stock movement']++;
                    $held = DB::table('stock')->where('branch_id', $b->branch_id)->where('catalog_product_id', $l->catalog_product_id)->exists();
                    $add('bill', $b->invoice_no, $b->date, $b->branch_id, (string) ($products[$l->catalog_product_id] ?? $l->catalog_product_id), (float) $l->qty,
                        $held ? 'no movement recorded' : 'the billing branch holds no stock row of this item');
                }
            }
        }

        // ── 2. Gold Saving renewals ──
        $pool = [];   // branch|date|amount => movements available to match
        foreach (DB::table('stock_movements')->where('ref_type', 'rd_entry')->get(['branch_id', 'moved_on', 'qty_change']) as $m) {
            $k = $m->branch_id . '|' . substr((string) $m->moved_on, 0, 10) . '|' . number_format(abs((float) $m->qty_change), 2, '.', '');
            $pool[$k] = ($pool[$k] ?? 0) + 1;
        }
        $entries = DB::table('rd_entries')->where('created_at', '>=', $from)->orderBy('id')->get();
        $sum['Renewals checked'] = $entries->count();
        $sum['Renewals WITHOUT a cash deduction'] = 0;
        $sum['Renewal amount not deducted ₹'] = 0.0;
        foreach ($entries as $e) {
            $k = $e->branch_id . '|' . substr((string) $e->paid_on, 0, 10) . '|' . number_format((float) $e->value, 2, '.', '');
            if (($pool[$k] ?? 0) > 0) {
                $pool[$k]--;

                continue;
            }
            $sum['Renewals WITHOUT a cash deduction']++;
            $sum['Renewal amount not deducted ₹'] += (float) $e->value;
            $add('renewal', 'RD-' . $e->id, $e->paid_on, $e->branch_id, 'CASH', (float) $e->value, 'no cash stock was chosen on the collection screen');
        }

        // ── 3. redemptions ──
        $redMoved = DB::table('stock_movements')->where('ref_type', 'redemption')->whereNotNull('ref_id')->distinct()->pluck('ref_id')->flip();
        $reds = DB::table('redemption_invoices as r')
            ->leftJoin('bonds as b', 'b.id', '=', 'r.bond_id')->leftJoin('plans as p', 'p.id', '=', 'b.plan_id')
            ->leftJoin('redeemable_qrs as q', 'q.id', '=', 'r.redeemable_qr_id')
            ->whereNull('r.deleted_at')->where('r.created_at', '>=', $from)
            ->get(['r.id', 'r.invoice_no', 'r.invoice_date', 'r.branch_id', 'p.type as plan_type', 'p.code as plan_code', 'q.source as qr_source']);
        $sum['Redemptions checked'] = $reds->count();
        $sum['Redemptions of a gold / silver PURCHASE bill (stock left at billing — by design)'] = 0;
        $sum['Redemptions WITHOUT a stock movement'] = 0;
        foreach ($reds as $r) {
            if (isset($redMoved[$r->id])) {
                continue;
            }
            $pieces = (float) DB::table('redemption_lines')->where('redemption_invoice_id', $r->id)->sum('quantity');
            if (in_array($r->plan_type, ['gold', 'silver'], true) && $r->qr_source !== 'legacy') {
                $sum['Redemptions of a gold / silver PURCHASE bill (stock left at billing — by design)']++;
                $add('redemption (purchase bill)', $r->invoice_no, $r->invoice_date, $r->branch_id, (string) $r->plan_code, $pieces, 'by design: the metal left the stock when the bill was made');

                continue;
            }
            $sum['Redemptions WITHOUT a stock movement']++;
            $add('redemption', $r->invoice_no, $r->invoice_date, $r->branch_id, (string) $r->plan_code, $pieces, 'no movement recorded');
        }

        // ── report ──
        $this->info('Stock deductions since ' . $from . ' (read-only)');
        $this->table(['Check', 'Count'], collect($sum)->map(fn ($v, $k) => [$k, is_float($v) ? number_format($v, 2) : $v])->values()->all());

        $path = $this->option('out') ?: storage_path('app/reports/stock-audit-deductions-' . now()->format('Ymd-His') . '.csv');
        @mkdir(dirname($path), 0775, true);
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Kind', 'Document', 'Date', 'Branch', 'Item / scheme', 'Qty or ₹', 'Reason']);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);
        $this->line(count($rows) . ' row(s) written to ' . $path);

        return self::SUCCESS;
    }
}
