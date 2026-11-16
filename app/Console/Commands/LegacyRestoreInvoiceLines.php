<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-16 (references 2476, 1720): some old redemption invoices came across with NO
 * lines — the old system's cart for them is gone — so the screen cannot say what was given and
 * no restock order can be raised.
 *
 * The old system still records the QR each of them redeemed (tbl_digi_queue: refferance = the
 * sale invoice no, route INVOICE, REDEEMED on the invoice date at the invoice's branch). A GOLD
 * QR of 0.100 g is one "100 mg Gold" coin. The line is rebuilt from it — one piece of the catalog
 * item of that weight, priced so that amount + making + wastage equals the invoice's taxable
 * value to the paisa (the invoice head is never touched).
 *
 * Restored ONLY when everything agrees:
 *   - as many redeemed QRs as line-less invoices for that sale / day / branch;
 *   - a catalog item of exactly the QR's weight;
 *   - the piece price the invoice implies is within 10 % of the QR's own price for that weight
 *     (an invoice worth far more than its QR had other items too — those stay as they are).
 *
 *   php artisan legacy:restore-invoice-lines                       dry run, every line-less old invoice
 *   php artisan legacy:restore-invoice-lines --ref=2476 --ref=1720 only these references
 *   php artisan legacy:restore-invoice-lines --ref=… --apply       write the lines (idempotent)
 */
class LegacyRestoreInvoiceLines extends Command
{
    protected $signature = 'legacy:restore-invoice-lines {--ref=* : only invoices with this reference no (repeatable)} {--apply : write the lines (default = dry run)} {--connection=legacy : legacy DB connection}';

    protected $description = 'Rebuild the missing lines of old redemption invoices from the QR the old system says they redeemed';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $apply = (bool) $this->option('apply');
        $refs = array_values(array_filter(array_map('strval', (array) $this->option('ref'))));
        $legacy = DB::connection((string) $this->option('connection'));

        $invoices = DB::table('redemption_invoices as i')->whereNotNull('i.legacy_restock')
            ->whereNotExists(fn ($q) => $q->from('redemption_lines as l')->whereColumn('l.redemption_invoice_id', 'i.id'))
            ->when($refs, fn ($q) => $q->whereIn('i.reference_no', $refs))
            ->orderBy('i.id')->get(['i.id', 'i.invoice_no', 'i.reference_no', 'i.invoice_date', 'i.branch_id', 'i.taxable_total', 'i.grand_total']);
        if ($invoices->isEmpty()) {
            $this->info('No line-less old redemption invoice' . ($refs ? ' with reference ' . implode(', ', $refs) : '') . '.');

            return self::SUCCESS;
        }

        $old = $legacy->table('tbl_qrsalesinvoice')->whereIn('estid', $invoices->pluck('invoice_no'))
            ->get(['estid', 'invno', 'estdate', 'branchid', 'netttotal'])->keyBy('estid');
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();

        // catalog items by material + grams per piece, and the usual making / wastage % of each
        $byWeight = [];
        foreach (CatalogProduct::whereIn('material', ['gold', 'silver'])->where('is_custom_order', false)->orderBy('id')->get() as $p) {
            $w = $p->unitWeight();
            if ($w > 0) {
                $byWeight[$p->material . '|' . number_format($w, 4, '.', '')] ??= $p;
            }
        }
        $charges = [];
        foreach ($legacy->table('tbl_salesqr_cart')->selectRaw('prid, mcharge, wcharge, count(*) c')->groupBy('prid', 'mcharge', 'wcharge')->orderByDesc('c')->get() as $r) {
            $charges[(string) $r->prid] ??= [(float) $r->mcharge, (float) $r->wcharge];
        }

        // line-less invoices of the same sale / day / branch share one set of redeemed QRs
        $groups = [];
        foreach ($invoices as $inv) {
            $o = $old[(int) $inv->invoice_no] ?? null;
            $groups[$o ? $o->invno . '|' . $o->estdate . '|' . $o->branchid : 'missing|' . $inv->id][] = $inv;
        }

        $rows = [];
        $done = 0;
        foreach ($groups as $key => $members) {
            $o = $old[(int) $members[0]->invoice_no] ?? null;
            $fail = function (string $why) use (&$rows, $members, $branchNames) {
                foreach ($members as $m) {
                    $rows[] = [$m->invoice_no, $m->reference_no, substr((string) $m->invoice_date, 0, 10), $branchNames[$m->branch_id] ?? '?', number_format((float) $m->taxable_total, 2), '—', 'left: ' . $why];
                }
            };
            if (! $o) {
                $fail('not in the old system');

                continue;
            }
            $qrs = $legacy->table('tbl_digi_queue')->where('refferance', (string) $o->invno)->where('route', 'INVOICE')
                ->where('deliverystatus', 'REDEEMED')->where('deliverydate', $o->estdate)->where('redeembranchid', (string) $o->branchid)
                ->orderBy('trid')->get(['trid', 'qrcode', 'qrmode', 'gm_worth', 'goldprice']);
            if ($qrs->isEmpty()) {
                $fail('the old system has no redeemed QR for this sale on that day at that branch');

                continue;
            }
            if ($qrs->count() !== count($members)) {
                $fail($qrs->count() . ' redeemed QR(s) for ' . count($members) . ' line-less invoice(s) — cannot pair them');

                continue;
            }
            foreach ($members as $n => $inv) {
                $qr = $qrs[$n];
                $material = strtolower((string) $qr->qrmode);
                $product = $byWeight[$material . '|' . number_format((float) $qr->gm_worth, 4, '.', '')] ?? null;
                if ($material !== 'gold' || ! $product) {
                    $rows[] = [$inv->invoice_no, $inv->reference_no, substr((string) $inv->invoice_date, 0, 10), $branchNames[$inv->branch_id] ?? '?', number_format((float) $inv->taxable_total, 2),
                        $qr->qrcode . ' ' . $qr->qrmode . ' ' . (float) $qr->gm_worth . ' g', 'left: ' . ($material !== 'gold' ? 'not a gold QR' : 'no catalog item of that weight')];

                    continue;
                }
                [$mc, $wc] = $charges[(string) $product->code] ?? [0.0, 0.0];
                $taxable = round((float) $inv->taxable_total, 2);
                $amount = round($taxable / (1 + ($mc + $wc) / 100), 2);
                $qrPrice = (float) $qr->goldprice * (float) $qr->gm_worth;
                if ($qrPrice <= 0 || abs($amount - $qrPrice) / $qrPrice > 0.10) {
                    $rows[] = [$inv->invoice_no, $inv->reference_no, substr((string) $inv->invoice_date, 0, 10), $branchNames[$inv->branch_id] ?? '?', number_format($taxable, 2),
                        $qr->qrcode . ' ' . $qr->qrmode . ' ' . (float) $qr->gm_worth . ' g',
                        sprintf('left: invoice implies ₹%s a piece, the QR priced it ₹%s — more than this one item was given', number_format($amount, 2), number_format($qrPrice, 2))];

                    continue;
                }
                $making = round($amount * $mc / 100, 2);
                $wastage = round($taxable - $amount - $making, 2);
                $name = \App\Support\Translatable::pick($product->name) ?? $product->code;
                $rows[] = [$inv->invoice_no, $inv->reference_no, substr((string) $inv->invoice_date, 0, 10), $branchNames[$inv->branch_id] ?? '?', number_format($taxable, 2),
                    $qr->qrcode . ' ' . $qr->qrmode . ' ' . (float) $qr->gm_worth . ' g',
                    sprintf('1 × %s — rate %s + making %s + wastage %s = %s', $name, number_format($amount, 2), number_format($making, 2), number_format($wastage, 2), number_format($taxable, 2))];
                $done++;
                if ($apply) {
                    DB::transaction(function () use ($inv, $product, $name, $qr, $amount, $making, $wastage, $taxable) {
                        if (DB::table('redemption_lines')->where('redemption_invoice_id', $inv->id)->lockForUpdate()->exists()) {
                            return;   // someone added lines meanwhile
                        }
                        DB::table('redemption_lines')->insert([
                            'redemption_invoice_id' => $inv->id, 'catalog_product_id' => $product->id, 'description' => mb_substr($name, 0, 200),
                            'hsn_code' => $product->hsn_code ?: '71082000', 'material' => $product->material,
                            'unit_weight' => (float) $qr->gm_worth, 'quantity' => 1, 'rate' => $amount,
                            'making' => $making, 'wastage' => $wastage, 'gst_pct' => 3, 'amount' => $amount, 'line_total' => $taxable,
                        ]);
                    });
                }
            }
        }

        $this->table(['Invoice', 'Ref', 'Date', 'Branch', 'Taxable ₹', 'QR redeemed (old system)', 'Line'], $rows);
        $this->line(sprintf('Line-less old invoices looked at: %d — %s: %d, left as they are: %d', $invoices->count(), $apply ? 'restored' : 'can be restored', $done, $invoices->count() - $done));
        $this->line($apply ? '<info>Applied.</info>' : '<comment>Dry run — nothing written. Re-run with --apply.</comment>');

        return self::SUCCESS;
    }
}
