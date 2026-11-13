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
 *   php artisan legacy:import-redemptions --fix-lines [--apply]   re-shape the lines already imported
 *
 * Mapping: invoice_no = legacy estid · bond/member via the sale invoice the QR came from
 * (tbl_qrsalesinvoice.invno = bonds.invoice_no) · branch via branches.legacy_brid · lines =
 * tbl_salesqr_cart rows of that sale invoice (when one sale has several redemption invoices, the
 * lines dated the same day as the redemption are taken; otherwise all of them).
 */
class LegacyImportRedemptions extends Command
{
    protected $signature = 'legacy:import-redemptions {--apply : write the rows (default = dry run)} {--fix-lines : rewrite the lines of invoices imported before 2026-11-13 in the invoice shape (Wt per piece, Qty, piece rate, making, HSN)} {--connection=legacy : legacy DB connection}';

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
        $products = DB::table('catalog_products')->get(['id', 'code', 'material', 'default_weight', 'hsn_code'])->keyBy('code')->all();
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
                // the goods were handed over in legacy; paymenttype there is the RESTOCK-order state
                // (ORDERED / APPROVED = the dealer already bought the stock back from HQ; PENDING = never did)
                'payment_mode' => 'passed',
                'legacy_restock' => strtolower(mb_substr((string) ($r->paymenttype ?: 'pending'), 0, 12)),
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
                $batchLines[$no][] = static::lineFromCart($c, $products[(string) $c->prid] ?? null);
            }
            $in++;
        }

        // --fix-lines: lines imported before 2026-11-13 are rewritten in the invoice shape
        if ($this->option('fix-lines')) {
            return $this->fixLines($rows, $carts, $products, $apply);
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

    /**
     * One legacy cart row as a redemption line, in the same shape the new system bills
     * (user 2026-11-13, comparing invoice 23687 with the old PDF): the legacy row
     * "100 mg Gold · 105 pcs · ₹1,437 a piece · 20 % + 20 % · ₹2,11,239 after charges" becomes
     *
     *   Wt 0.1 g · Qty 105 · Rate 1,437 · amount 1,50,885 · making 30,177 · wastage 30,177 · HSN 71082000
     *
     * amount + making + wastage is always the legacy after-charges value to the paisa (the
     * invoice head — taxable / GST / grand — is untouched). Until 2026-11-13 the import stored the
     * piece count as the weight, Qty 1, the gold market rate as the rate (even on silver) and no
     * making; such lines carry a NULL HSN, which is how the readers tell the two shapes apart.
     */
    public static function lineFromCart(object $c, ?object $product): array
    {
        $material = strtolower(mb_substr((string) ($c->catename ?: 'gold'), 0, 20));
        $pieces = (float) $c->weight;
        $price = (float) $c->purprice;
        $after = round((float) ($c->cg_aftercharge ?: $c->aftercharges), 2);
        $amount = round($pieces * $price, 2);
        if ($amount <= 0 || $amount > $after + 0.005) {
            $amount = $after;   // no piece price on record: everything is goods value
        }
        $making = min(round($amount * (float) $c->mcharge / 100, 2), round($after - $amount, 2));
        $wastage = round($after - $amount - $making, 2);

        return [
            'catalog_product_id' => $product->id ?? null,
            'description' => mb_substr((string) ($c->prname ?: ''), 0, 200) ?: null,
            'hsn_code' => ($product->hsn_code ?? null) ?: ($material === 'silver' ? '71189000' : '71082000'),
            'material' => $material,
            'unit_weight' => (float) ($product->default_weight ?? 0) ?: \App\Support\Gst\GstAuditorReport::gramsPerPiece((string) $c->prname),
            'quantity' => $pieces,
            'rate' => $price ?: (float) $c->matprice,
            'making' => $making,
            'wastage' => $wastage,
            'gst_pct' => (float) ($c->gsti ?: 3),
            'amount' => $amount,
            'line_total' => $after,
        ];
    }

    /**
     * Rewrite the lines of the redemption invoices ALREADY imported in the old line shape (NULL
     * HSN) from the legacy cart, and put the sponsor — not the buyer's own name — in "Referred
     * Distributor". Invoice heads keep their amounts; an invoice whose rewritten lines would not
     * add up to the lines it has now is left alone and counted.
     */
    protected function fixLines($rows, $carts, array $products, bool $apply): int
    {
        $invoices = DB::table('redemption_invoices')->whereNotNull('legacy_restock')
            ->get(['id', 'invoice_no', 'member_id', 'referrer_name'])->keyBy('invoice_no');
        $oldShape = DB::table('redemption_lines')->whereNull('hsn_code')
            ->selectRaw('redemption_invoice_id, COUNT(*) as n, SUM(amount) as amt')
            ->groupBy('redemption_invoice_id')->get()->keyBy('redemption_invoice_id');
        $sponsor = DB::table('members as m')->join('members as u', 'u.id', '=', 'm.upline_id')
            ->whereIn('m.id', $invoices->pluck('member_id')->filter()->unique()->values())
            ->pluck('u.name', 'm.id');

        $todo = [];
        $mismatch = 0;
        $referrers = [];
        foreach ($rows as $r) {
            $inv = $invoices->get((string) (int) $r->estid);
            if (! $inv) {
                continue;
            }
            $up = $inv->member_id ? ($sponsor[$inv->member_id] ?? null) : null;
            if ($up && $up !== $inv->referrer_name) {
                $referrers[$inv->id] = mb_substr((string) $up, 0, 200);
            }
            $old = $oldShape->get($inv->id);
            if (! $old) {
                continue;   // no lines, or already in the invoice shape
            }
            $cart = $carts->get((string) $r->invno) ?? collect();
            $sameDay = $cart->filter(fn ($c) => (string) $c->cartaddeddate === (string) $r->estdate);
            $lines = ($sameDay->isNotEmpty() ? $sameDay : $cart)
                ->map(fn ($c) => static::lineFromCart($c, $products[(string) $c->prid] ?? null))->values();
            if ($lines->count() !== (int) $old->n || abs($lines->sum('line_total') - (float) $old->amt) > 0.05) {
                $mismatch++;

                continue;
            }
            $todo[$inv->id] = $lines->all();
        }

        $this->info(sprintf('%s: %d imported invoice(s) get their lines rewritten (Wt per piece, Qty, piece rate, making / wastage, HSN); %d get the sponsor as Referred Distributor; %d left alone (legacy cart no longer matches).',
            $apply ? 'APPLY' : 'DRY RUN', count($todo), count($referrers), $mismatch));
        if (! $apply) {
            $this->line('Nothing written. Run with --fix-lines --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($todo, $referrers) {
            foreach (array_chunk(array_keys($todo), 500) as $ids) {
                DB::table('redemption_lines')->whereIn('redemption_invoice_id', $ids)->whereNull('hsn_code')->delete();
                $insert = [];
                foreach ($ids as $id) {
                    foreach ($todo[$id] as $l) {
                        $insert[] = ['redemption_invoice_id' => $id] + $l;
                    }
                }
                foreach (array_chunk($insert, 500) as $chunk) {
                    DB::table('redemption_lines')->insert($chunk);
                }
            }
            foreach ($referrers as $id => $name) {
                DB::table('redemption_invoices')->where('id', $id)->update(['referrer_name' => $name]);
            }
        });
        $this->info('Done — invoice totals were not touched.');

        return self::SUCCESS;
    }
}
