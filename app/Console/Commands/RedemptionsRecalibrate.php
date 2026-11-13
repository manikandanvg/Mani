<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\RedemptionInvoice;
use App\Services\Redeem\RedemptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lord team 2026-11-13 — a QR from a past-dated (old system) invoice is redeemed at the FROZEN
 * gold price of its own date, never the live price.
 *
 * Re-calibrates the redemption invoices raised on the new system (since 1 Nov 2026) on such QRs:
 * every line is re-priced at the QR's frozen price — gold + making + wastage is the bill and the 3 % GST
 * is taken OUT of it (RDM-HQ-0405: ₹4,10,245.86 = 3,98,296.95 + 11,948.91) — and the invoice head follows. An invoice already standing at the frozen price comes
 * out identical and is skipped, so this is safe to run again; one that was set to the live price
 * is brought back to the frozen price.
 *
 * Stock, GM margin, the QR and old-system invoices are never touched.
 *
 *   php artisan redemptions:recalibrate                  dry run — invoice now vs frozen price
 *   php artisan redemptions:recalibrate --id=4598        one invoice
 *   php artisan redemptions:recalibrate --apply          write it
 *   php artisan redemptions:recalibrate --id=4601 --gst-on-top [--apply]
 *        an invoice raised 13–14 Nov with the GST taken out of the rows → GST added on top (board 2026-11-14)
 */
class RedemptionsRecalibrate extends Command
{
    protected $signature = 'redemptions:recalibrate {--apply : write the frozen-price figures (default = dry run)}
        {--id= : one redemption invoice id only}
        {--since=2026-11-01 : invoices dated on or after this day (new-system go-live)}
        {--except= : invoice ids to leave alone, comma separated (e.g. 4598)}
        {--gst-on-top : also re-state the invoices raised 13–14 Nov 2026 with the GST INSIDE the rows to GST ON TOP (use with --id for one invoice)}';

    protected $description = 'Re-price new-system redemption invoices on past-dated QRs at the frozen QR-date gold price';

    public function handle(RedemptionService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $one = $this->option('id') ? (int) $this->option('id') : null;

        if (! \Illuminate\Support\Facades\Schema::hasColumn('redemption_invoices', 'frozen_gold_rate')) {
            $this->error('The frozen-price columns are missing. Run "php artisan migrate" first, then run this again.');

            return self::FAILURE;
        }

        $invoices = RedemptionInvoice::with(['lines', 'qr', 'branch'])
            ->whereNull('legacy_restock')                                     // raised on the new system
            ->whereNotNull('redeemable_qr_id')->has('qr')
            ->when($one, fn ($q) => $q->whereKey($one), fn ($q) => $q->whereDate('invoice_date', '>=', (string) $this->option('since')))
            ->orderBy('id')->get();

        $table = [];
        $skipped = [];
        $changed = 0;
        $except = array_filter(array_map('intval', explode(',', (string) $this->option('except'))));
        foreach ($invoices as $inv) {
            if (in_array((int) $inv->id, $except, true)) {
                continue;   // Head Office asked to keep this one as it is
            }
            if (in_array($inv->tax_regime, ['vat', 'none'], true)) {
                continue;
            }
            $billed = $svc->billedRates($inv->qr);
            if ($billed['gold'] <= 0 && $billed['silver'] <= 0) {
                continue;   // no billed-date price on record for this QR: it was (rightly) priced live
            }
            if ($inv->lines->contains(fn ($l) => (float) $l->unit_weight <= 0)) {
                continue;   // Mode A: the cart IS the original bill's lines, already at billed-date prices
            }
            [$rate, $market] = $this->ratesFor($inv, $billed);
            $priced = [];
            foreach ($inv->lines as $l) {
                $cp = $l->catalog_product_id ? CatalogProduct::find($l->catalog_product_id) : null;
                if (! $cp) {
                    $priced = null;
                    break;
                }
                $priced[] = [$l, $svc->priceLine($cp, (float) $l->unit_weight, (float) $l->quantity, $rate)];
            }
            if (! $priced) {
                $skipped[] = $inv->id;

                continue;
            }

            $taxable = round(array_sum(array_map(fn ($p) => $p[1]['taxable'], $priced)), 2);
            $gst = round(array_sum(array_map(fn ($p) => $p[1]['gst'], $priced)), 2);
            $cgst = round($gst / 2, 2);
            $sgst = round($gst - $cgst, 2);
            $grand = round($taxable + $gst, 2);
            // Raised 13–14 Nov 2026 at the frozen price with the GST taken OUT of the row value (the
            // rule of those two days): its grand total is today's taxable. The board moved the GST
            // back on top on 14 Nov for NEW invoices only — these keep their figures.
            $gstInsideAtFrozen = abs($taxable - (float) $inv->grand_total) < 0.01;
            if (($gstInsideAtFrozen && ! $this->option('gst-on-top')) || (abs($grand - (float) $inv->grand_total) < 0.01 && abs($taxable - (float) $inv->taxable_total) < 0.01)) {
                // already at the frozen price — only make sure the bill carries the billed-date prices
                if ($apply && $inv->frozen_gold_rate === null && $inv->frozen_silver_rate === null) {
                    DB::table('redemption_invoices')->where('id', $inv->id)->update($this->headRates($rate, $market, $billed));
                }

                continue;
            }
            $changed++;
            $metal = $inv->qr->qr_mode === 'silver' ? 'silver' : 'gold';
            $table[] = [$inv->id, $inv->invoice_no, substr((string) $inv->invoice_date, 0, 10), mb_substr((string) $inv->branch?->name, 0, 22),
                number_format((float) $inv->qr->cash_worth, 2),
                number_format((float) ($inv->{'frozen_' . $metal . '_rate'} ?? $inv->{$metal . '_rate'}), 2) . ' → ' . number_format((float) $rate->{$metal}, 2),
                number_format((float) $inv->grand_total, 2), number_format($grand, 2),
                number_format($taxable, 2) . ' + ' . number_format($gst, 2)];

            if ($apply) {
                $head = $this->headRates($rate, $market, $billed);
                DB::transaction(function () use ($inv, $priced, $head, $taxable, $gst, $cgst, $sgst, $grand) {
                    foreach ($priced as [$l, $p]) {
                        DB::table('redemption_lines')->where('id', $l->id)->update([
                            'rate' => $p['rate'], 'making' => $p['making'], 'wastage' => $p['wastage'],
                            'gst_pct' => $p['gst_pct'], 'amount' => $p['amount'], 'line_total' => $p['line_total'],
                        ]);
                    }
                    DB::table('redemption_invoices')->where('id', $inv->id)->update($head + [
                        'taxable_total' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst, 'tax_total' => $gst,
                        'grand_total' => $grand, 'updated_at' => now(),
                    ]);
                });
            }
        }

        if ($table) {
            $this->table(['ID', 'Invoice', 'Date', 'Branch', 'QR worth', 'Rate now → frozen', 'Invoice now', 'At frozen price', 'Taxable + GST'], $table);
        }
        $this->info(sprintf('%s: %d redemption invoice(s) on past-dated QRs checked since %s; %d %s the frozen price.',
            $apply ? 'APPLY' : 'DRY RUN', $invoices->count(), $one ? "id {$one}" : (string) $this->option('since'), $changed,
            $apply ? 're-calibrated to' : 'differ from'));
        if ($skipped) {
            $this->warn('Skipped (a line has no catalog item, cannot be re-priced): ' . implode(', ', $skipped));
        }
        if (! $apply && $changed) {
            $this->comment('Nothing written. Add --apply to re-calibrate them.');
        }

        return self::SUCCESS;
    }

    /**
     * [the feed redeem() bills with, the live market feed of the redemption day or null].
     * Feed = gold and silver at the billed-date (frozen) prices; a metal with no billed-date price
     * keeps the price the invoice was raised with.
     *
     * @return array{0:LiveRate,1:?LiveRate}
     */
    protected function ratesFor(RedemptionInvoice $inv, array $billed): array
    {
        $country = $inv->branch?->country ?: 'IN';
        $at = $inv->created_at ?: $inv->invoice_date;
        $market = LiveRate::where('country', $country)->where('effective_at', '<=', $at)->latest('effective_at')->first();

        $rate = new LiveRate(['country' => $country]);
        $rate->exists = true;
        foreach (['gold', 'silver'] as $m) {
            $rate->{$m} = $billed[$m] > 0 ? $billed[$m] : (float) ($market?->{$m} ?? $inv->{$m . '_rate'});
        }

        return [$rate, $market];
    }

    /** Invoice head: live market price on top of the bill (when that day is on record) + the frozen prices used. */
    protected function headRates(LiveRate $rate, ?LiveRate $market, array $billed): array
    {
        return ($market ? ['gold_rate' => (float) $market->gold, 'silver_rate' => (float) $market->silver] : []) + [
            'frozen_gold_rate' => (float) $rate->gold ?: null,
            'frozen_silver_rate' => (float) $rate->silver ?: null,
            'frozen_on' => $billed['date'] ?? null,
        ];
    }
}
