<?php

namespace App\Console\Commands;

use App\Services\Redeem\RedemptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-13 — redemption invoice 4598 printed Taxable Value ₹4,10,245.86 = GRAND TOTAL:
 * the value was GST-INCLUSIVE (rate + making + wastage + GST, as carted) but went onto the
 * invoice with no tax split. Ruling: the cart value IS the bill; the GST is taken out of it.
 *
 * Re-states the redemption invoices raised on the NEW system that carry no tax on top
 * (grand total = taxable value) under the GST regime:
 *
 *      taxable = grand ÷ 1.03      GST = grand − taxable      CGST / SGST = half each
 *
 * The grand total, the lines, stock and commissions are never touched. Invoices imported from
 * the old system (legacy_restock set) are left exactly as the old system billed them.
 *
 *   php artisan redemptions:restate-inclusive                dry run — lists what would change
 *   php artisan redemptions:restate-inclusive --apply        write it
 *   php artisan redemptions:restate-inclusive --id=4598      one invoice only
 */
class RedemptionsRestateInclusive extends Command
{
    protected $signature = 'redemptions:restate-inclusive {--apply : write the split (default = dry run)}
        {--since=2026-11-01 : only invoices dated on or after this day (new-system go-live)}
        {--id= : one redemption invoice id only}';

    protected $description = 'Split the GST out of new-system redemption invoices that were saved GST-inclusive with no tax (Taxable Value = Grand Total)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $pct = RedemptionService::GST_INCLUSIVE_PCT;

        $base = DB::table('redemption_invoices as i')
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->whereNull('i.legacy_restock')                       // raised on the new system
            ->where('i.grand_total', '>', 0)
            ->whereRaw('ABS(i.grand_total - i.taxable_total) < 0.01')   // nothing was added on top
            ->when($this->option('id'),
                fn ($q) => $q->where('i.id', (int) $this->option('id')),
                fn ($q) => $q->whereDate('i.invoice_date', '>=', (string) $this->option('since')))
            ->orderBy('i.id')
            ->select('i.id', 'i.invoice_no', 'i.invoice_date', 'i.taxable_total', 'i.cgst', 'i.sgst', 'i.tax_total', 'i.grand_total',
                'i.tax_regime', 'b.name as branch', 'b.tax_regime as branch_regime');

        $rows = $base->get();
        $todo = $rows->filter(fn ($r) => ! in_array($r->tax_regime, ['vat', 'none'], true) && ! in_array($r->branch_regime, ['vat', 'none'], true));
        $skipped = $rows->count() - $todo->count();

        if ($todo->isEmpty()) {
            $this->info('No new-system redemption invoice has Taxable Value = Grand Total. Nothing to re-state.');
        } else {
            // why each one had no tax: the GST % stamped on its lines
            $linePct = DB::table('redemption_lines')->whereIn('redemption_invoice_id', $todo->pluck('id'))
                ->selectRaw('redemption_invoice_id, GROUP_CONCAT(DISTINCT gst_pct) as pcts')
                ->groupBy('redemption_invoice_id')->pluck('pcts', 'redemption_invoice_id');

            $table = [];
            foreach ($todo as $r) {
                [$taxable, $gst] = RedemptionService::splitInclusive((float) $r->grand_total, $pct);
                $cgst = round($gst / 2, 2);
                $sgst = round($gst - $cgst, 2);
                $table[] = [$r->id, $r->invoice_no, substr((string) $r->invoice_date, 0, 10), mb_substr((string) $r->branch, 0, 28),
                    number_format((float) $r->taxable_total, 2), number_format($taxable, 2), number_format($gst, 2),
                    number_format((float) $r->grand_total, 2), $linePct[$r->id] ?? '—'];

                if ($apply) {
                    DB::transaction(function () use ($r, $taxable, $gst, $cgst, $sgst, $pct) {
                        DB::table('redemption_invoices')->where('id', $r->id)->update([
                            'taxable_total' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst,
                            'tax_total' => $gst, 'tax_regime' => 'gst', 'updated_at' => now(),
                        ]);
                        // the lines keep their (inclusive) values; only the rate they were billed at is stamped
                        DB::table('redemption_lines')->where('redemption_invoice_id', $r->id)
                            ->where('gst_pct', '<=', 0)->where(fn ($q) => $q->whereNull('material')->orWhere('material', '!=', 'cash'))
                            ->update(['gst_pct' => $pct]);
                    });
                }
            }
            $this->table(['ID', 'Invoice', 'Date', 'Branch', 'Taxable now', 'Taxable (÷ 1.03)', 'GST', 'Grand total (same)', 'Line GST %'], $table);
            $this->info(sprintf('%s: %d redemption invoice(s) %s — grand total unchanged, GST of ₹%s split out of it.',
                $apply ? 'APPLY' : 'DRY RUN', $todo->count(), $apply ? 're-stated' : 'would be re-stated',
                number_format($todo->sum(fn ($r) => RedemptionService::splitInclusive((float) $r->grand_total, $pct)[1]), 2)));
        }

        if ($skipped) {
            $this->warn("{$skipped} more have no tax because the invoice / branch is on the VAT or tax-free regime — left alone.");
        }
        if (! $apply && $todo->isNotEmpty()) {
            $this->comment('Nothing written. Add --apply to re-state them.');
        }

        return self::SUCCESS;
    }
}
