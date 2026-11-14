<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 2026-11-14 — live bill INV-B200-0110 (G10 Gold Purchase, 1 g gold): saved at ₹13,210 flat,
 * no making, no wastage, no GST, while the Sales screen showed ₹14,286.62. The screen kept a
 * line's charges in hidden form fields that never reached the saved bill (fixed the same day).
 *
 * READ-ONLY list of every gold / silver bill line raised on the new system that was saved
 * without its charges: what was billed, what the product's making + wastage + GST make it, and
 * the short amount — by bill and by branch. Nothing is written.
 *
 *   php artisan sales:audit-missing-charges
 *   php artisan sales:audit-missing-charges --csv=storage/app/bills-missing-charges.csv
 */
class SalesAuditMissingCharges extends Command
{
    protected $signature = 'sales:audit-missing-charges {--since=2026-11-01 : bills dated on or after this day}
        {--csv= : also write the full list to this CSV file}';

    protected $description = 'Gold / silver bills saved without making, wastage and GST (read-only)';

    public function handle(): int
    {
        $since = (string) $this->option('since');
        $lines = DB::table('sale_lines as l')
            ->join('sales_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('catalog_products as p', 'p.id', '=', 'l.catalog_product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->leftJoin('plans as pl', 'pl.id', '=', 'i.plan_id')
            ->whereIn('p.material', ['gold', 'silver'])
            ->where('p.is_custom_order', false)
            ->whereDate('i.date', '>=', $since)
            ->where('i.created_at', '>=', $since)                       // raised on the new system, not imported history
            ->where(fn ($q) => $q->whereNull('i.status')->orWhere('i.status', '!=', 'cancelled'))
            ->orderBy('i.id')
            ->get(['i.id as invoice_id', 'i.invoice_no', 'i.date', 'i.customer_name', 'i.grand_total', 'i.tax_total', 'b.name as branch',
                'pl.code as plan', 'p.name as product', 'p.material', 'p.making_charge_pct', 'p.wastage_charge_pct', 'p.hallmark_charge', 'p.gst_pct',
                'l.qty', 'l.rate', 'l.making', 'l.wastage', 'l.line_total']);

        $rows = [];
        $byBranch = [];
        $checked = 0;
        foreach ($lines as $l) {
            $checked++;
            $base = round((float) $l->qty * (float) $l->rate, 2);
            $charges = round($base * ((float) $l->making_charge_pct + (float) $l->wastage_charge_pct) / 100 + (float) $l->hallmark_charge, 2);
            $taxable = round($base + $charges, 2);
            $gst = round($taxable * (float) $l->gst_pct / 100, 2);
            $should = round($taxable + $gst, 2);
            $short = round($should - (float) $l->line_total, 2);
            if ($short < 0.5) {
                continue;   // billed in full
            }
            $name = json_decode((string) $l->product, true)['en'] ?? (string) $l->product;
            $rows[] = [
                'Bill' => $l->invoice_no, 'Date' => substr((string) $l->date, 0, 10), 'Branch' => $l->branch, 'Scheme' => $l->plan,
                'Customer' => $l->customer_name, 'Item' => $name, 'Grams' => (float) $l->qty, 'Rate ₹/g' => (float) $l->rate,
                'Billed ₹' => (float) $l->line_total, 'Making+wastage due ₹' => $charges, 'GST due ₹' => $gst,
                'Should be ₹' => $should, 'Short ₹' => $short,
            ];
            $b = &$byBranch[$l->branch ?: '—'];
            $b ??= ['n' => 0, 'billed' => 0.0, 'charges' => 0.0, 'gst' => 0.0, 'short' => 0.0];
            $b['n']++;
            $b['billed'] += (float) $l->line_total;
            $b['charges'] += $charges;
            $b['gst'] += $gst;
            $b['short'] += $short;
            unset($b);
        }

        if (! $rows) {
            $this->info("READ-ONLY: {$checked} gold / silver bill line(s) since {$since} checked — all carry their charges.");

            return self::SUCCESS;
        }

        $m = fn ($v) => number_format((float) $v, 2);
        $this->table(['Bill', 'Date', 'Branch', 'Scheme', 'Item', 'Grams', 'Billed', 'Mk+Wst due', 'GST due', 'Should be', 'Short'],
            array_map(fn ($r) => [$r['Bill'], $r['Date'], mb_substr((string) $r['Branch'], 0, 20), $r['Scheme'], $r['Item'], $r['Grams'],
                $m($r['Billed ₹']), $m($r['Making+wastage due ₹']), $m($r['GST due ₹']), $m($r['Should be ₹']), $m($r['Short ₹'])], array_slice($rows, 0, 60)));
        if (count($rows) > 60) {
            $this->comment('… ' . (count($rows) - 60) . ' more — use --csv for the full list.');
        }
        uasort($byBranch, fn ($a, $b) => $b['short'] <=> $a['short']);
        $this->table(['Branch', 'Lines', 'Billed ₹', 'Making+wastage short ₹', 'GST short ₹', 'Total short ₹'],
            collect($byBranch)->map(fn ($b, $k) => [mb_substr((string) $k, 0, 28), $b['n'], $m($b['billed']), $m($b['charges']), $m($b['gst']), $m($b['short'])])->values()->all());
        $this->info(sprintf('READ-ONLY: %d gold / silver bill line(s) since %s checked; %d were saved without their charges — short ₹%s in all (making + wastage ₹%s, GST ₹%s). Nothing was changed.',
            $checked, $since, count($rows), $m(array_sum(array_column($rows, 'Short ₹'))),
            $m(array_sum(array_column($rows, 'Making+wastage due ₹'))), $m(array_sum(array_column($rows, 'GST due ₹')))));

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
