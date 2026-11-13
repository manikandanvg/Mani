<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\RedemptionInvoice;
use App\Services\Redeem\RedemptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-13 — redemption invoice 4598: a ₹4,00,000 QR, ₹4,00,085 of gold carted on the
 * Redeem Stock QR screen (making + wastage + 3 % GST already in every cart row), but the tax
 * invoice came out at ₹4,10,245.86.
 *
 * Cause: the QR was carried over from the old system, so the invoice priced its gold at the
 * rate of the QR's own date, while the screen carted the very same pieces at TODAY's rate. The
 * operator handed over gold against the total the screen showed. (Fixed going forward — screen
 * and invoice now price from RedemptionService::rateFor().)
 *
 * This lists every new-system redemption invoice billed on a QR-date rate, re-prices its lines
 * at the live rate of the redemption day — the total the screen showed — and, with --apply,
 * re-states the invoice to that cart value: taxable + GST = cart total, nothing added on top.
 * Stock, GM margin and the QR are never touched; old-system invoices are never touched.
 *
 *   php artisan redemptions:audit-cart-rate                 dry run — billed vs cart, per invoice
 *   php artisan redemptions:audit-cart-rate --id=4598       one invoice, line by line
 *   php artisan redemptions:audit-cart-rate --id=4598 --apply
 *   php artisan redemptions:audit-cart-rate --gold=12730    force the day's gold rate (dry run prints the one it found)
 */
class RedemptionsAuditCartRate extends Command
{
    protected $signature = 'redemptions:audit-cart-rate {--apply : re-state the invoices to the cart value (default = dry run)}
        {--id= : one redemption invoice id only}
        {--since=2026-11-01 : invoices dated on or after this day (new-system go-live)}
        {--gold= : gold rate ₹/g the screen carted with, when the rate table no longer holds that day}
        {--silver= : silver rate ₹/g the screen carted with}';

    protected $description = 'Find redemption invoices billed at a legacy QR-date rate while the screen carted at the live rate, and re-state them to the cart value';

    public function handle(RedemptionService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $one = $this->option('id') ? (int) $this->option('id') : null;

        $invoices = RedemptionInvoice::with(['lines', 'qr', 'branch'])
            ->whereNull('legacy_restock')                                     // raised on the new system
            ->whereHas('qr', fn ($q) => $q->where('frozen_rate', '>', 0))     // billed at the QR-date rate
            ->when($one, fn ($q) => $q->whereKey($one), fn ($q) => $q->whereDate('invoice_date', '>=', (string) $this->option('since')))
            ->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            $this->info($one
                ? "Invoice #{$one} was not billed on a legacy QR-date rate (or is an old-system invoice) — nothing to compare."
                : 'No new-system redemption invoice was billed on a legacy QR-date rate.');

            return self::SUCCESS;
        }

        $table = [];
        $changed = 0;
        $over = 0.0;
        foreach ($invoices as $inv) {
            if (in_array($inv->tax_regime, ['vat', 'none'], true)) {
                continue;
            }
            $rate = $this->cartRateOn($inv);
            $lines = [];
            $ok = true;
            foreach ($inv->lines as $l) {
                $cp = $l->catalog_product_id ? CatalogProduct::find($l->catalog_product_id) : null;
                if (! $cp) {
                    $ok = false;
                    break;
                }
                $lines[] = [$l, $svc->priceLine($cp, (float) $l->unit_weight, (float) $l->quantity, $rate)];
            }
            if (! $ok || ! $lines) {
                $table[] = [$inv->id, $inv->invoice_no, substr((string) $inv->invoice_date, 0, 10), '—', '—', number_format((float) $inv->grand_total, 2), 'a line has no catalog item — skipped', ''];

                continue;
            }

            $taxable = round(array_sum(array_map(fn ($p) => $p[1]['taxable'], $lines)), 2);
            $gst = round(array_sum(array_map(fn ($p) => $p[1]['gst'], $lines)), 2);
            $cgst = round($gst / 2, 2);
            $sgst = round($gst - $cgst, 2);
            $cart = round($taxable + $gst, 2);
            $diff = round((float) $inv->grand_total - $cart, 2);
            if (abs($diff) < 0.01) {
                continue;   // screen and invoice agreed
            }
            $changed++;
            $over += $diff;
            $metal = $inv->qr->qr_mode === 'silver' ? 'silver' : 'gold';
            $table[] = [$inv->id, $inv->invoice_no, substr((string) $inv->invoice_date, 0, 10),
                number_format((float) $inv->qr->cash_worth, 2),
                number_format((float) $inv->qr->frozen_rate, 2) . ' → ' . number_format((float) $rate->{$metal}, 2),
                number_format((float) $inv->grand_total, 2), number_format($cart, 2),
                number_format($taxable, 2) . ' + ' . number_format($gst, 2)];

            if ($one) {
                $this->table(['Item', 'Wt', 'Qty', 'Billed (incl. GST)', 'Cart on screen (incl. GST)'], array_map(fn ($p) => [
                    $p[0]->description, (float) $p[0]->unit_weight, (float) $p[0]->quantity,
                    number_format((float) $p[0]->line_total, 2), number_format($p[1]['line_total'], 2)], $lines));
            }

            if ($apply) {
                DB::transaction(function () use ($inv, $lines, $rate, $taxable, $gst, $cgst, $sgst, $cart) {
                    foreach ($lines as [$l, $p]) {
                        DB::table('redemption_lines')->where('id', $l->id)->update([
                            'rate' => $p['rate'], 'making' => $p['making'], 'wastage' => $p['wastage'],
                            'gst_pct' => $p['gst_pct'], 'amount' => $p['amount'], 'line_total' => $p['line_total'],
                        ]);
                    }
                    DB::table('redemption_invoices')->where('id', $inv->id)->update([
                        'gold_rate' => (float) $rate->gold, 'silver_rate' => (float) $rate->silver,
                        'taxable_total' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst, 'tax_total' => $gst,
                        'grand_total' => $cart, 'updated_at' => now(),
                    ]);
                });
            }
        }

        if (! $table) {
            $this->info('Every invoice billed on a legacy QR-date rate already equals its cart. Nothing to re-state.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Invoice', 'Date', 'QR worth', 'Rate billed → rate carted', 'Invoice now', 'Cart on screen', 'Taxable + GST (new)'], $table);
        $this->info(sprintf('%s: %d invoice(s) %s the cart value; invoices are ₹%s %s the carts in all.',
            $apply ? 'APPLY' : 'DRY RUN', $changed, $apply ? 're-stated to' : 'differ from',
            number_format(abs($over), 2), $over >= 0 ? 'above' : 'below'));
        if (! $apply && $changed) {
            $this->comment('Nothing written. Check "Cart on screen" against what the counter saw, then add --apply.');
        }

        return self::SUCCESS;
    }

    /** The live feed the redeem screen carted with on the invoice's day (options override the table). */
    protected function cartRateOn(RedemptionInvoice $inv): LiveRate
    {
        $country = $inv->branch?->country ?: 'IN';
        $at = $inv->created_at ?: $inv->invoice_date;
        $rate = LiveRate::where('country', $country)->where('effective_at', '<=', $at)->latest('effective_at')->first()
            ?? LiveRate::where('country', $country)->oldest('effective_at')->first()
            ?? new LiveRate(['gold' => 0, 'silver' => 0]);
        $rate = $rate->replicate();
        $rate->exists = true;
        foreach (['gold', 'silver'] as $m) {
            if ($this->option($m) !== null) {
                $rate->{$m} = (float) $this->option($m);
            }
        }

        return $rate;
    }
}
