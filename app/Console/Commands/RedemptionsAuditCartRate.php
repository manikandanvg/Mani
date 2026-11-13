<?php

namespace App\Console\Commands;

use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\RedemptionInvoice;
use App\Services\Redeem\RedemptionService;
use Illuminate\Console\Command;

/**
 * Lord team 2026-11-13 — redemption invoice 4598: a ₹4,00,000 QR from a past-dated (old system)
 * invoice, ₹4,00,085 of gold carted on the Redeem Stock QR screen, tax invoice ₹4,10,245.86.
 *
 * Ruling: a past-dated QR is redeemed at the FROZEN gold price of its own date. The invoice did
 * that; the screen did not — it carted the same pieces at TODAY's price, so the counter saw a
 * smaller total and handed over more gold than the QR was worth. (Fixed going forward — screen
 * and invoice now both price from RedemptionService::rateFor().)
 *
 * This only LISTS the invoices raised with that mismatch. The invoice figures are right (frozen
 * price) and are never changed; the list shows what the screen showed and how far each invoice
 * is over its QR worth — the gold handed over in excess. What to do about each is Head Office's
 * decision.
 *
 *   php artisan redemptions:audit-cart-rate                 every such invoice since go-live
 *   php artisan redemptions:audit-cart-rate --id=4598       one invoice, line by line
 *   php artisan redemptions:audit-cart-rate --gold=12730    the live gold rate the screen used, when the rate table no longer holds that day
 */
class RedemptionsAuditCartRate extends Command
{
    protected $signature = 'redemptions:audit-cart-rate {--id= : one redemption invoice id only}
        {--since=2026-11-01 : invoices dated on or after this day (new-system go-live)}
        {--gold= : live gold rate ₹/g the screen carted with, when the rate table no longer holds that day}
        {--silver= : live silver rate ₹/g the screen carted with}';

    protected $description = 'List redemption invoices billed at the frozen QR-date price while the screen carted at the live price (read-only)';

    public function handle(RedemptionService $svc): int
    {
        $one = $this->option('id') ? (int) $this->option('id') : null;

        $invoices = RedemptionInvoice::with(['lines', 'qr', 'branch'])
            ->whereNull('legacy_restock')                                     // raised on the new system
            ->whereHas('qr', fn ($q) => $q->where('frozen_rate', '>', 0))     // a past-dated QR, billed at its frozen price
            ->when($one, fn ($q) => $q->whereKey($one), fn ($q) => $q->whereDate('invoice_date', '>=', (string) $this->option('since')))
            ->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            $this->info($one
                ? "Invoice #{$one} was not raised on a past-dated QR (or is an old-system invoice) — nothing to compare."
                : 'No new-system redemption invoice was raised on a past-dated QR.');

            return self::SUCCESS;
        }

        $table = [];
        $excess = 0.0;
        foreach ($invoices as $inv) {
            $live = $this->liveRateOn($inv);
            $cart = 0.0;
            $detail = [];
            $ok = $inv->lines->isNotEmpty();
            foreach ($inv->lines as $l) {
                $cp = $l->catalog_product_id ? CatalogProduct::find($l->catalog_product_id) : null;
                if (! $cp) {
                    $ok = false;
                    break;
                }
                $p = $svc->priceLine($cp, (float) $l->unit_weight, (float) $l->quantity, $live);
                $cart += $p['line_total'];
                $detail[] = [$l->description, (float) $l->unit_weight, (float) $l->quantity,
                    number_format($p['line_total'], 2), number_format((float) $l->line_total, 2)];
            }
            $cart = round($cart, 2);
            $billed = (float) $inv->grand_total;
            if ($ok && abs($billed - $cart) < 0.01) {
                continue;   // screen and invoice agreed (frozen = live that day)
            }
            $metal = $inv->qr->qr_mode === 'silver' ? 'silver' : 'gold';
            $worth = (float) $inv->qr->cash_worth;
            $over = round($billed - $worth, 2);
            $excess += max(0, $over);
            $table[] = [$inv->id, $inv->invoice_no, substr((string) $inv->invoice_date, 0, 10), mb_substr((string) $inv->branch?->name, 0, 24),
                number_format($worth, 2),
                number_format((float) $inv->qr->frozen_rate, 2), number_format((float) $live->{$metal}, 2),
                $ok ? number_format($cart, 2) : 'a line has no catalog item',
                number_format($billed, 2), number_format($over, 2)];

            if ($one && $detail) {
                $this->table(['Item', 'Wt', 'Qty', 'Screen showed (live price)', 'Invoice (frozen price)'], $detail);
            }
        }

        if (! $table) {
            $this->info('Every invoice raised on a past-dated QR equals the cart the screen showed. Nothing to report.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Invoice', 'Date', 'Branch', 'QR worth', 'Frozen ₹/g', 'Live ₹/g', 'Screen showed', 'Invoice (correct)', 'Over QR worth'], $table);
        $this->info(sprintf('%d invoice(s) were carted at the live price and billed at the frozen price. Gold handed over above the QR worth: ₹%s in all.',
            count($table), number_format($excess, 2)));
        $this->comment('Read-only: nothing was changed. The invoices carry the correct (frozen) price.');

        return self::SUCCESS;
    }

    /** The live feed the redeem screen carted with on the invoice's day (options override the table). */
    protected function liveRateOn(RedemptionInvoice $inv): LiveRate
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
