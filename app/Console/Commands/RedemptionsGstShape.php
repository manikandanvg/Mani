<?php

namespace App\Console\Commands;

use App\Models\RedemptionInvoice;
use Illuminate\Console\Command;

/**
 * Lord's board 2026-11-14 — "which invoices had the GST added to the rows and which did not?"
 *
 * The rule changed three times in November, so the redemption invoices raised on the new system
 * are not all built the same way. This READ-ONLY list says, invoice by invoice, how each stands:
 *
 *   ON TOP    rows (rate + making + wastage) = taxable; 3 % GST added → grand = rows + GST
 *   INSIDE    rows = grand total; the GST was taken OUT of the rows (rule of 13–14 Nov 2026)
 *   NO GST    rows = grand total and no tax at all
 *   OTHER     none of the above — look at it by hand
 *
 * and how far the grand total is above the QR's worth. Nothing is written.
 *
 *   php artisan redemptions:gst-shape
 *   php artisan redemptions:gst-shape --csv=storage/app/redemption-gst-shape.csv
 */
class RedemptionsGstShape extends Command
{
    protected $signature = 'redemptions:gst-shape {--since=2026-11-01 : invoices dated on or after this day}
        {--csv= : also write the full list to this CSV file}';

    protected $description = 'List new-system redemption invoices as GST ON TOP of the rows or GST INSIDE the rows (read-only)';

    public function handle(): int
    {
        $since = (string) $this->option('since');
        $rows = [];
        $count = [];
        foreach (RedemptionInvoice::with(['lines', 'qr', 'branch'])->whereNull('legacy_restock')
            ->whereDate('invoice_date', '>=', $since)->orderBy('id')->get() as $inv) {
            $value = round((float) $inv->lines->sum(fn ($l) => (float) $l->amount + (float) $l->making + (float) $l->wastage), 2);
            $taxable = (float) $inv->taxable_total;
            $tax = (float) $inv->tax_total;
            $grand = (float) $inv->grand_total;
            $near = fn (float $a, float $b) => abs($a - $b) < 0.06;

            $shape = match (true) {
                $tax < 0.01 && $near($value, $grand) => 'NO GST',
                $near($value, $taxable) && $near($taxable + $tax, $grand) => 'ON TOP',
                $near($value, $grand) && $near($taxable + $tax, $grand) => 'INSIDE',
                default => 'OTHER',
            };
            $count[$shape] = ($count[$shape] ?? 0) + 1;
            $worth = (float) ($inv->qr?->cash_worth ?? 0);

            $rows[] = [
                'ID' => $inv->id, 'Invoice' => $inv->invoice_no, 'Date' => substr((string) $inv->invoice_date, 0, 10),
                'Branch' => $inv->branch?->name, 'QR' => $inv->qr?->qr_code, 'QR worth ₹' => $worth,
                'Rows (rate+MC+WC) ₹' => $value, 'Taxable ₹' => $taxable, 'GST ₹' => $tax, 'Grand total ₹' => $grand,
                'GST' => $shape, 'Grand above QR worth ₹' => $worth > 0 ? round($grand - $worth, 2) : null,
                'Gold price used' => (float) ($inv->frozen_gold_rate ?: $inv->gold_rate),
            ];
        }

        if (! $rows) {
            $this->info("No new-system redemption invoice since {$since}.");

            return self::SUCCESS;
        }

        $m = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
        $this->table(['ID', 'Invoice', 'Date', 'QR worth', 'Rows', 'Taxable', 'GST', 'Grand', 'GST is', 'Above QR'],
            array_map(fn ($r) => [$r['ID'], $r['Invoice'], $r['Date'], $m($r['QR worth ₹']), $m($r['Rows (rate+MC+WC) ₹']), $m($r['Taxable ₹']),
                $m($r['GST ₹']), $m($r['Grand total ₹']), $r['GST'], $m($r['Grand above QR worth ₹'])], array_slice($rows, 0, 80)));
        if (count($rows) > 80) {
            $this->comment('… ' . (count($rows) - 80) . ' more — use --csv for the full list.');
        }
        ksort($count);
        $this->table(['GST is', 'Invoices'], collect($count)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(sprintf('READ-ONLY: %d redemption invoice(s) since %s. Nothing was changed.', count($rows), $since));

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info('Full list written to ' . $csv);
        }

        return self::SUCCESS;
    }
}
