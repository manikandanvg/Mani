<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-04: the GST report showed only cash bills for ETHIERA GOLD MINE (Oct 2026) — the
 * legacy REDEMPTION invoices (tbl_qrsalesinvoice + tbl_salesqr_cart, 4,352 rows since Feb 2026)
 * were never imported by legacy:rollout, so redemption_invoices was empty on live.
 *
 * Imports them idempotently (a legacy row is skipped when its invoice number is already there),
 * so it is safe on the live database after go-live:
 *
 *   php artisan legacy:import-redemptions            dry run — counts per branch, unmatched
 *   php artisan legacy:import-redemptions --apply    write the invoices + lines
 *
 * Mapping: invoice_no = legacy estid · bond/member via the sale invoice the QR came from
 * (tbl_qrsalesinvoice.invno = bonds.invoice_no) · branch via branches.legacy_brid · lines =
 * tbl_salesqr_cart rows of that sale invoice (when one sale has several redemption invoices, the
 * lines dated the same day as the redemption are taken; otherwise all of them).
 */
class LegacyImportRedemptions extends Command
{
    protected $signature = 'legacy:import-redemptions {--apply : write the rows (default = dry run)} {--connection=legacy : legacy DB connection}';

    protected $description = 'Import legacy redemption (QR sale) invoices and their lines into redemption_invoices / redemption_lines (idempotent)';

    public function handle(): int
    {
        // the bond / sale / cart lookups are held in memory; the 128 MB CLI default dies silently (exit 255)
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $apply = (bool) $this->option('apply');
        $legacy = DB::connection((string) $this->option('connection'));

        $branchByBrid = DB::table('branches')->whereNotNull('legacy_brid')->pluck('id', 'legacy_brid')->all();
        $bondByInvoice = DB::table('bonds')->whereNotNull('invoice_no')->select('id', 'member_id', 'invoice_no')->get()
            ->keyBy('invoice_no')->all();
        $saleByNo = DB::table('sales_invoices')->select('id', 'customer_member_id', 'invoice_no')->get()->keyBy('invoice_no')->all();
        $qrByInvoice = DB::table('redeemable_qrs')->whereNotNull('invoice_no')->pluck('id', 'invoice_no')->all();
        $productByCode = DB::table('catalog_products')->pluck('id', 'code')->all();
        $existing = DB::table('redemption_invoices')->pluck('id', 'invoice_no')->all();

        $rows = $legacy->table('tbl_qrsalesinvoice')->orderBy('estid')->get();
        $carts = $legacy->table('tbl_salesqr_cart')->get()->groupBy('invno');

        $in = 0;
        $skipped = 0;
        $noBranch = 0;
        $noBond = 0;
        $noLines = 0;
        $perBranch = [];
        $batchInv = [];
        $batchLines = [];   // invoice_no => [lines]

        foreach ($rows as $r) {
            $no = (string) (int) $r->estid;
            if (isset($existing[$no])) {
                $skipped++;
                continue;
            }
            $branchId = $branchByBrid[(int) $r->branchid] ?? null;
            if (! $branchId) {
                $noBranch++;
            }
            $bond = $bondByInvoice[(string) $r->invno] ?? null;
            $sale = $saleByNo[(string) $r->invno] ?? null;
            if (! $bond) {
                $noBond++;
            }
            $date = $r->estdate ?: '2026-01-01';
            $cgst = (float) $r->cgst;
            $sgst = (float) $r->sgst;
            $batchInv[] = [
                'invoice_no' => $no,
                'invoice_date' => $date,
                'redeemable_qr_id' => $qrByInvoice[(string) $r->invno] ?? null,
                'bond_id' => $bond->id ?? null,
                'member_id' => $bond->member_id ?? ($sale->customer_member_id ?? null),
                'branch_id' => $branchId,
                'reference_no' => mb_substr((string) ($r->invno ?: ''), 0, 60),         // the sale invoice the QR came from
                'referrer_name' => mb_substr((string) ($r->cusname ?: ''), 0, 200),
                'payment_mode' => strtolower(mb_substr((string) ($r->paymenttype ?: 'pending'), 0, 20)),   // legacy restock state
                'payment_reference' => mb_substr((string) ($r->paymentremarks ?: ''), 0, 120) ?: null,
                'gold_rate' => (float) $r->goldrate,
                'silver_rate' => (float) $r->silverrate,
                'taxable_total' => (float) $r->netttotal,
                'cgst' => $cgst, 'sgst' => $sgst,
                'tax_total' => round($cgst + $sgst, 2),
                'tax_regime' => 'gst',
                'grand_total' => (float) $r->grandtotal,
                'currency_code' => 'INR', 'fx_rate' => 1,
                'buyer_gst' => null,
                'dealer_created' => (int) $r->branchid !== 1 ? 1 : 0,
                'created_by' => null,
                'created_at' => $date . ' 00:00:00', 'updated_at' => $date . ' 00:00:00',
            ];
            $perBranch[$branchId ?: 0] = ($perBranch[$branchId ?: 0] ?? 0) + 1;

            // lines: the cart rows of the sale invoice; several redemptions on one sale → same-day rows
            $cart = $carts->get((string) $r->invno) ?? collect();
            $sameDay = $cart->filter(fn ($c) => (string) $c->cartaddeddate === (string) $r->estdate);
            $lines = $sameDay->isNotEmpty() ? $sameDay : $cart;
            if ($lines->isEmpty()) {
                $noLines++;
            }
            foreach ($lines as $c) {
                $batchLines[$no][] = [
                    'catalog_product_id' => $productByCode[(string) $c->prid] ?? null,
                    'description' => mb_substr((string) ($c->prname ?: ''), 0, 200) ?: null,
                    'hsn_code' => null,
                    'material' => strtolower(mb_substr((string) ($c->catename ?: 'gold'), 0, 20)),
                    'unit_weight' => (float) $c->weight,
                    'quantity' => 1,
                    'rate' => (float) ($c->matprice ?: $c->purprice),
                    'making' => (float) $c->cg_mc,
                    'wastage' => (float) $c->cg_wc,
                    'gst_pct' => (float) ($c->gsti ?: 3),
                    'amount' => (float) ($c->cg_aftercharge ?: $c->aftercharges),
                    'line_total' => (float) ($c->cg_aftercharge ?: $c->aftercharges),
                ];
            }
            $in++;
        }

        $names = DB::table('branches')->pluck('name', 'id')->all();
        arsort($perBranch);
        $this->table(['Branch', 'Redemption invoices to import'],
            collect($perBranch)->take(15)->map(fn ($n, $b) => [$b ? ($names[$b] ?? "#$b") : '— no branch match —', $n])->values()->all());
        $this->info(sprintf('%s: %d legacy redemption invoices to import, %d already present (skipped), %d without a branch match, %d without a bond match, %d without lines.',
            $apply ? 'APPLY' : 'DRY RUN', $in, $skipped, $noBranch, $noBond, $noLines));

        if (! $apply || $in === 0) {
            if (! $apply) {
                $this->line('Nothing written. Run with --apply to import.');
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($batchInv, $batchLines) {
            foreach (array_chunk($batchInv, 500) as $chunk) {
                DB::table('redemption_invoices')->insert($chunk);
            }
            $ids = DB::table('redemption_invoices')->whereIn('invoice_no', array_keys($batchLines))->pluck('id', 'invoice_no')->all();
            $lines = [];
            foreach ($batchLines as $no => $rows) {
                foreach ($rows as $l) {
                    $lines[] = ['redemption_invoice_id' => $ids[$no]] + $l;
                }
            }
            foreach (array_chunk($lines, 500) as $chunk) {
                DB::table('redemption_lines')->insert($chunk);
            }
        });
        $this->info("Imported $in redemption invoices with their lines — the GST report and GSTR-1 export now include them.");

        return self::SUCCESS;
    }
}
