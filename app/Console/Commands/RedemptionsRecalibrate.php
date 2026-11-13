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
 */
class RedemptionsRecalibrate extends Command
{
    protected $signature = 'redemptions:recalibrate {--apply : write the frozen-price figures (default = dry run)}
        {--id= : one redemption invoice id only}
        {--since=2026-11-01 : invoices dated on or after this day (new-system go-live)}';

    protected $description = 'Re-price new-system redemption invoices on past-dated QRs at the frozen QR-date gold price';

    public function handle(RedemptionService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $one = $this->option('id') ? (int) $this->option('id') : null;

        $invoices = RedemptionInvoice::with(['lines', 'qr', 'branch'])
            ->whereNull('legacy_restock')                                     // raised on the new system
            ->whereHas('qr', fn ($q) => $q->where('frozen_rate', '>', 0))     // a past-dated QR
            ->when($one, fn ($q) => $q->whereKey($one), fn ($q) => $q->whereDate('invoice_date', '>=', (string) $this->option('since')))
            ->orderBy('id')->get();

        $table = [];
        $skipped = [];
        $changed = 0;
        foreach ($invoices as $inv) {
            if (in_array($inv->tax_regime, ['vat', 'none'], true)) {
                continue;
            }
            $rate = $this->frozenRateFor($inv);
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
            if (abs($grand - (float) $inv->grand_total) < 0.01 && abs($taxable - (float) $inv->taxable_total) < 0.01) {
                continue;   // already at the frozen price
            }
            $changed++;
            $metal = $inv->qr->qr_mode === 'silver' ? 'silver' : 'gold';
            $table[] = [$inv->id, $inv->invoice_no, substr((string) $inv->invoice_date, 0, 10), mb_substr((string) $inv->branch?->name, 0, 22),
                number_format((float) $inv->qr->cash_worth, 2),
                number_format((float) $inv->{$metal . '_rate'}, 2) . ' → ' . number_format((float) $inv->qr->frozen_rate, 2),
                number_format((float) $inv->grand_total, 2), number_format($grand, 2),
                number_format($taxable, 2) . ' + ' . number_format($gst, 2)];

            if ($apply) {
                DB::transaction(function () use ($inv, $priced, $rate, $taxable, $gst, $cgst, $sgst, $grand) {
                    foreach ($priced as [$l, $p]) {
                        DB::table('redemption_lines')->where('id', $l->id)->update([
                            'rate' => $p['rate'], 'making' => $p['making'], 'wastage' => $p['wastage'],
                            'gst_pct' => $p['gst_pct'], 'amount' => $p['amount'], 'line_total' => $p['line_total'],
                        ]);
                    }
                    DB::table('redemption_invoices')->where('id', $inv->id)->update([
                        'gold_rate' => (float) $rate->gold, 'silver_rate' => (float) $rate->silver,
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

    /** The feed redeem() bills this invoice with: the day's live rates, the QR's own metal at its frozen price. */
    protected function frozenRateFor(RedemptionInvoice $inv): LiveRate
    {
        $country = $inv->branch?->country ?: 'IN';
        $at = $inv->created_at ?: $inv->invoice_date;
        $rate = LiveRate::where('country', $country)->where('effective_at', '<=', $at)->latest('effective_at')->first()
            ?? LiveRate::where('country', $country)->oldest('effective_at')->first()
            ?? new LiveRate(['gold' => 0, 'silver' => 0]);
        $rate = $rate->replicate();
        $rate->exists = true;
        $rate->{$inv->qr->qr_mode === 'silver' ? 'silver' : 'gold'} = (float) $inv->qr->frozen_rate;

        return $rate;
    }
}
