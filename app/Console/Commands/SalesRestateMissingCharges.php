<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\CatalogProduct;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\Plan;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use App\Models\SalesInvoice;
use App\Services\Activity\ActivityLogger;
use App\Services\Charges\ChargeResolver;
use App\Services\NetworkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 2026-11-14 — the gold / silver bills the Sales page saved WITHOUT making, wastage and GST
 * (sales:audit-missing-charges lists them) are brought to the amount the screen showed.
 *
 * For every such bill, in one transaction:
 *   bill line        material, making, wastage, line total (metal + charges + GST)
 *   bill head        cross / net total, CGST, SGST, tax, grand total, received
 *   bond             value (plan's bond share of the bill) and the full bill (epin_value)
 *   instant comm.    each level's row is % of the bill: a PENDING row is raised to the right
 *                    amount; where the row is already approved / paid a new pending row carries
 *                    the difference. A row that was capped by the earning limit is left alone.
 *   BV / group BV    the buyer's BV and every upline's group BV (+ ranking BV where the scheme
 *                    counts) grow by the difference; ranks are re-evaluated
 *   QR               still pending → its worth follows the bill
 *   redemption       already redeemed (the customer's own purchase) → that tax invoice gets the
 *                    same making, wastage and GST
 *
 * Stock, gold / silver margin and cash are not touched (they never depended on the charges).
 * Dry run by default. Run it again any time: a bill already right is skipped.
 *
 *   php artisan sales:restate-missing-charges                       dry run, every short bill
 *   php artisan sales:restate-missing-charges --bill=INV-B200-0110  one bill
 *   php artisan sales:restate-missing-charges --apply               write it
 */
class SalesRestateMissingCharges extends Command
{
    protected $signature = 'sales:restate-missing-charges {--apply : write the corrections (default = dry run)}
        {--bill= : one bill number only}
        {--since=2026-11-01 : bills dated on or after this day}';

    protected $description = 'Re-state gold / silver bills saved without making, wastage and GST to the amount the screen showed';

    public function handle(NetworkService $network): int
    {
        $apply = (bool) $this->option('apply');
        $since = (string) $this->option('since');

        $invoiceIds = DB::table('sale_lines as l')
            ->join('sales_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('catalog_products as p', 'p.id', '=', 'l.catalog_product_id')
            ->whereIn('p.material', ['gold', 'silver'])->where('p.is_custom_order', false)
            ->whereDate('i.date', '>=', $since)->where('i.created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('i.status')->orWhere('i.status', '!=', 'cancelled'))
            ->when($this->option('bill'), fn ($q, $no) => $q->where('i.invoice_no', $no))
            ->distinct()->orderBy('i.id')->pluck('i.id');

        $table = [];
        $tot = ['bills' => 0, 'bill' => 0.0, 'ic' => 0.0, 'bv' => 0.0, 'qr' => 0, 'rdm' => 0];
        $notes = [];
        foreach ($invoiceIds as $id) {
            $plan = $this->plan(SalesInvoice::with('lines')->findOrFail($id));
            if (! $plan) {
                continue;
            }
            $run = fn () => $this->restate((int) $id, $apply, $network);
            $r = $apply ? DB::transaction($run) : $run();
            if (! $r) {
                continue;
            }
            $tot['bills']++;
            $tot['bill'] += $r['grand_new'] - $r['grand_old'];
            $tot['ic'] += $r['ic_delta'];
            $tot['bv'] += $r['grand_new'] - $r['grand_old'];
            $tot['qr'] += $r['qr'] === 'worth updated' ? 1 : 0;
            $tot['rdm'] += $r['redemption'] !== '—' ? 1 : 0;
            $m = fn ($v) => number_format((float) $v, 2);
            $table[] = [$r['bill'], $r['plan'], $m($r['grand_old']), $m($r['grand_new']), $m($r['bond_old']) . ' → ' . $m($r['bond_new']),
                $m($r['ic_delta']) . ' (' . $r['ic_rows'] . ')', $r['qr'], $r['redemption']];
            foreach ($r['notes'] as $n) {
                $notes[] = $r['bill'] . ': ' . $n;
            }
        }

        if (! $table) {
            $this->info('No gold / silver bill is short of its charges — nothing to re-state.');

            return self::SUCCESS;
        }
        $this->table(['Bill', 'Scheme', 'Bill now', 'Should be', 'Bond', 'Commission added (rows)', 'QR', 'Redemption invoice'], $table);
        foreach ($notes as $n) {
            $this->warn($n);
        }
        $this->info(sprintf('%s: %d bill(s) %s; bills + ₹%s (same amount added to BV / group BV), instant commission + ₹%s, %d QR worth updated, %d redemption invoice(s) re-stated.',
            $apply ? 'APPLIED' : 'DRY RUN', $tot['bills'], $apply ? 're-stated' : 'would be re-stated', number_format($tot['bill'], 2),
            number_format($tot['ic'], 2), $tot['qr'], $tot['rdm']));
        if (! $apply) {
            $this->comment('Nothing written. Take a database backup, then add --apply.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null  null = the bill is already right */
    protected function restate(int $invoiceId, bool $apply, NetworkService $network): ?array
    {
        $invoice = SalesInvoice::with('lines')->lockForUpdate()->findOrFail($invoiceId);
        $plan = $this->plan($invoice);
        $fx = (float) ($invoice->fx_rate ?: 1);

        // ---- lines
        $addNet = 0.0;
        $addGst = 0.0;
        $fixed = [];
        foreach ($invoice->lines as $l) {
            $cp = $l->catalog_product_id ? CatalogProduct::find($l->catalog_product_id) : null;
            if (! $cp || ! in_array($cp->material, ['gold', 'silver'], true) || $cp->is_custom_order) {
                continue;
            }
            $base = round((float) $l->qty * (float) $l->rate, 2);
            $c = $cp->charges((float) $l->qty);
            $making = round($base * $c['making_pct'] / 100, 2);
            $wastage = round($base * $c['wastage_pct'] / 100, 2);
            $net = round($base + $making + $wastage + (float) $cp->hallmark_charge, 2);
            $gst = round($net * (float) $cp->gst_pct / 100, 2);
            $total = round($net + $gst, 2);
            if ($total - (float) $l->line_total < 0.5) {
                continue;
            }
            // what the line carried before: its total, with whatever of it was above the bare metal
            $oldNet = min((float) $l->line_total, round($base + (float) $l->making + (float) $l->wastage, 2));
            $addNet += $net - $oldNet;
            $addGst += $gst - max(0.0, round((float) $l->line_total - $oldNet, 2));
            $fixed[] = [$l, $cp, $making, $wastage, $net, $gst, $total];
        }
        if (! $fixed) {
            return null;
        }

        $crossOld = (float) $invoice->cross_total;
        $grandOld = (float) $invoice->grand_total;
        $crossNew = round($crossOld + $addNet, 2);
        $taxNew = round((float) $invoice->tax_total + $addGst, 2);
        $grandNew = round($crossNew + $taxNew, 2);
        $notes = [];

        // ---- bond
        $bond = Bond::where('invoice_no', $invoice->invoice_no)->where('member_id', $invoice->customer_member_id)->first();
        $bondOld = (float) ($bond->value ?? 0);
        $bondNew = $bond ? $plan->bondValueFor(round($crossNew * $fx, 2)) : 0.0;
        if (! $bond) {
            $notes[] = 'no bond found for this bill — bill and lines only';
        }

        // ---- instant commission: every level is % of the (pre-tax) bill
        $icDelta = 0.0;
        $icRows = 0;
        $icPlan = [];
        if ($bond) {
            foreach (CommissionLedger::where('type', 'IC')->where('bond_id', $bond->id)->where('invoice_no', $invoice->invoice_no)->orderBy('level')->get() as $row) {
                $pct = (float) (($plan->ic_schedule ?? [])[(int) $row->level - 1] ?? 0);
                $was = round($crossOld * $fx * $pct / 100, 2);
                $due = round($crossNew * $fx * $pct / 100, 2);
                if ($pct <= 0 || abs((float) $row->amount - $was) > 0.02) {
                    if ($pct > 0) {
                        $notes[] = "commission level {$row->level} was not the plain % of the bill (earning limit?) — left as it is";
                    }

                    continue;
                }
                $delta = round($due - (float) $row->amount, 2);
                if ($delta < 0.01) {
                    continue;
                }
                $icDelta += $delta;
                $icRows++;
                $icPlan[] = [$row, $due, $delta];
            }
        }

        // ---- QR / redemption
        $qr = $bond ? RedeemableQr::where('bond_id', $bond->id)->where('invoice_no', $invoice->invoice_no)->orderBy('id')->first() : null;
        $qrNote = '—';
        $rdmNote = '—';
        $redemption = null;
        if ($qr && $qr->status === 'pending' && abs((float) $qr->cash_worth - $grandOld * $fx) < 0.02) {
            $qrNote = 'worth updated';
        } elseif ($qr && $qr->status === 'redeemed') {
            $qrNote = 'already redeemed';
            $redemption = RedemptionInvoice::with('lines')->where('redeemable_qr_id', $qr->id)->whereNull('legacy_restock')->first();
            $rdmNote = $redemption ? $redemption->invoice_no : 'not found';
        } elseif ($qr) {
            $qrNote = 'left (' . $qr->status . ')';
        }

        $result = ['bill' => $invoice->invoice_no, 'plan' => $plan->code, 'grand_old' => $grandOld, 'grand_new' => $grandNew,
            'bond_old' => $bondOld, 'bond_new' => $bondNew, 'ic_delta' => $icDelta, 'ic_rows' => $icRows, 'qr' => $qrNote,
            'redemption' => $rdmNote, 'notes' => $notes];
        if (! $apply) {
            return $result;
        }

        // ================= write =================
        foreach ($fixed as [$l, $cp, $making, $wastage, $net, $gst, $total]) {
            DB::table('sale_lines')->where('id', $l->id)->update([
                'material' => $l->material ?: $cp->material, 'making' => $making, 'wastage' => $wastage, 'line_total' => $total,
            ]);
        }
        $half = round($taxNew / 2, 2);
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'cross_total' => $crossNew, 'net_total' => $crossNew, 'cgst' => $half, 'sgst' => round($taxNew - $half, 2),
            'tax_total' => $taxNew, 'grand_total' => $grandNew,
            'received' => abs((float) $invoice->received - $grandOld) < 0.02 ? $grandNew : $invoice->received,
            'updated_at' => now(),
        ]);

        if ($bond) {
            DB::table('bonds')->where('id', $bond->id)->update(['value' => $bondNew, 'epin_value' => round($grandNew * $fx, 2), 'updated_at' => now()]);

            foreach ($icPlan as [$row, $due, $delta]) {
                if ($row->status === 'pending') {
                    DB::table('commission_ledger')->where('id', $row->id)->update(['amount' => $due, 'updated_at' => now()]);
                } else {
                    // already approved / paid at the short amount — the difference waits for approval
                    CommissionLedger::create([
                        'type' => 'IC', 'member_id' => $row->member_id, 'from_member_id' => $row->from_member_id, 'bond_id' => $row->bond_id,
                        'invoice_no' => $row->invoice_no, 'level' => $row->level, 'amount' => $delta, 'status' => 'pending',
                        'earned_on' => $row->earned_on, 'branch_id' => $row->branch_id,
                        'currency_code' => $row->currency_code, 'fx_rate' => $row->fx_rate,
                    ]);
                }
            }

            // BV / group BV grow by the bill as billed (tax included), ranking BV by the plan's factor
            $member = Member::find($bond->member_id);
            $delta = round(($grandNew - $grandOld) * $fx, 2);
            if ($member && $delta > 0) {
                $unpure = round($delta * $plan->rankFactor(), 2);
                $member->increment('bv', $delta);
                $unpure > 0 && $member->increment('unpure_bv', $unpure);
                $chain = [$member->id];
                $seen = [$member->id => true];
                $ancestor = $member->upline;
                while ($ancestor && ! isset($seen[$ancestor->id])) {
                    $seen[$ancestor->id] = true;
                    $ancestor->increment('gbv', $delta);
                    $unpure > 0 && $ancestor->increment('unpure_gbv', $unpure);
                    $chain[] = $ancestor->id;
                    $ancestor = $ancestor->upline;
                }
                if ($unpure > 0) {
                    foreach ($chain as $memberId) {
                        $network->evaluateRank($memberId);
                    }
                }
            }
        }

        if ($qrNote === 'worth updated') {
            $worth = round($grandNew * $fx, 2);
            $rate = (float) $invoice->gold_rate;
            DB::table('redeemable_qrs')->where('id', $qr->id)->update([
                'cash_worth' => $worth,
                'gram_worth' => $qr->qr_mode !== 'silver' && $rate > 0 ? app(ChargeResolver::class)->gramsForWorth($worth, $rate) : $qr->gram_worth,
                'updated_at' => now(),
            ]);
        }

        if ($redemption) {
            $this->restateRedemption($redemption, $fixed);
        }

        ActivityLogger::log('billing', 'bill_charges_restated',
            sprintf('Bill %s re-stated with its making, wastage and GST: ₹%s → ₹%s', $invoice->invoice_no, number_format($grandOld, 2), number_format($grandNew, 2)),
            ['subject' => $invoice, 'details' => $result]);

        return $result;
    }

    /** The customer's own purchase, already redeemed: its tax invoice copied the short bill lines. */
    protected function restateRedemption(RedemptionInvoice $redemption, array $fixed): void
    {
        $taxable = 0.0;
        $gstTotal = 0.0;
        foreach ($redemption->lines as $rl) {
            $match = collect($fixed)->first(fn ($f) => (int) $f[1]->id === (int) $rl->catalog_product_id
                && abs((float) $f[0]->qty - (float) $rl->quantity) < 0.0001);
            if ($match) {
                [, $cp, $making, $wastage, $net, $gst, $total] = $match;
                DB::table('redemption_lines')->where('id', $rl->id)->update([
                    'material' => $rl->material ?: $cp->material, 'making' => round($making + (float) $cp->hallmark_charge, 2), 'wastage' => $wastage,
                    'gst_pct' => (float) $cp->gst_pct, 'line_total' => $total,
                ]);
                $taxable += $net;
                $gstTotal += $gst;
            } else {
                $value = round((float) $rl->amount + (float) $rl->making + (float) $rl->wastage, 2);
                $taxable += $value;
                $gstTotal += max(0.0, round((float) $rl->line_total - $value, 2));
            }
        }
        $half = round($gstTotal / 2, 2);
        DB::table('redemption_invoices')->where('id', $redemption->id)->update([
            'taxable_total' => round($taxable, 2), 'cgst' => $half, 'sgst' => round($gstTotal - $half, 2),
            'tax_total' => round($gstTotal, 2), 'grand_total' => round($taxable + $gstTotal, 2), 'updated_at' => now(),
        ]);
    }

    protected function plan(SalesInvoice $invoice): ?Plan
    {
        return $invoice->plan_id ? Plan::find($invoice->plan_id) : null;
    }
}
