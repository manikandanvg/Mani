<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Models\RdEntry;
use App\Services\Qr\GoldQrPricing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lord's board 2026-11-13 — Gold Saving (G11 PLUS 1 / 2 / 3) rules, to apply from 1 Nov 2026.
 *
 * READ-ONLY report: every joining bill and renewal collected on a G11 bond since go-live, set
 * against the board's rule for that payment — what the customer paid the distributor, what the
 * rule would have collected, the excess (+) or short (−), and the gold QR given against the gold due.
 *
 *   PLUS 1 (P209)  amount = whole 100 mg pieces × that day's 100 mg price (rate + MC + WC + GST);
 *                  pieces are fixed at joining; a gold QR for the pieces every month.
 *   PLUS 2 (P208)  joining in ₹1,500 multiples; month 2 collects pieces × that day's 100 mg price
 *                  and gives the gold QR; the other months collect the joining amount.
 *   PLUS 3 (P200)  joining ₹1,000 + steps of ₹500; every renewal = the joining amount.
 *
 * A bond billed before go-live keeps its legacy piece count (bond value ÷ ₹1,500).
 * Nothing is written.
 *
 *   php artisan rd:g11-audit
 *   php artisan rd:g11-audit --csv=storage/app/g11-audit.csv
 *   php artisan rd:g11-audit --gold=12695      rate for days the rate table no longer holds
 */
class RdG11Audit extends Command
{
    protected $signature = 'rd:g11-audit {--since=2026-11-01 : payments dated on or after this day}
        {--csv= : also write the full list to this CSV file}
        {--gold= : gold rate ₹/g for a day with no rate on record}';

    protected $description = 'Gold Saving (G11) payments since go-live against the board rules: excess / short and gold given (read-only)';

    protected const PLUS1 = 'P209';

    protected const PLUS2 = 'P208';

    protected const PLUS3 = 'P200';

    public function handle(GoldQrPricing $pricing): int
    {
        $since = (string) $this->option('since');
        $plans = Plan::whereIn('code', [self::PLUS1, self::PLUS2, self::PLUS3])->get()->keyBy('id');
        $pricePlan = $plans->firstWhere('code', self::PLUS1) ?? $plans->first(fn ($p) => (float) $p->rd_qr_grams > 0);
        if (! $pricePlan) {
            $this->error('No Gold Saving plan with a 100 mg QR weight found.');

            return self::FAILURE;
        }
        $price = function (float $rate) use ($pricing, $pricePlan): float {
            return $rate > 0 ? $pricing->price($pricePlan, 'IN', $rate) : 0.0;
        };

        $bondIds = collect()
            ->merge(Bond::whereIn('plan_id', $plans->keys())->whereDate('bond_date', '>=', $since)->pluck('id'))
            ->merge(RdEntry::whereIn('bond_id', Bond::whereIn('plan_id', $plans->keys())->select('id'))->whereDate('paid_on', '>=', $since)->pluck('bond_id'))
            ->unique();

        $rows = [];
        $tot = [];
        foreach (Bond::with(['member', 'branch'])->whereIn('id', $bondIds)->orderBy('id')->get() as $bond) {
            $code = $plans[$bond->plan_id]->code;
            $isNew = substr((string) $bond->bond_date, 0, 10) >= $since;
            $joinPaid = (float) ($bond->epin_value ?: $bond->value);
            $inv = DB::table('sales_invoices')->where('invoice_no', $bond->invoice_no)->first(['gold_rate', 'created_at']);
            $joinRate = (float) ($inv->gold_rate ?? 0) ?: $this->rateAt($inv->created_at ?? $bond->created_at);
            $joinPrice = $price($joinRate);
            $qrs = DB::table('redeemable_qrs')->where('bond_id', $bond->id)->orderBy('id')->get();
            $qrOn = fn ($at) => $qrs->filter(fn ($q) => substr((string) $q->created_at, 0, 10) === substr((string) $at, 0, 10));

            // pieces fixed for the scheme
            $pieces = match (true) {
                $code === self::PLUS1 && $isNew => $joinPrice > 0 ? (int) floor($joinPaid / $joinPrice + 0.0001) : 0,
                $code === self::PLUS1 => max(1, intdiv((int) floor((float) $bond->value), 1500)),   // legacy rule kept
                $code === self::PLUS2 => max(1, intdiv((int) floor($joinPaid), 1500)),
                default => 0,
            };

            $payments = [];
            if ($isNew) {
                $payments[] = ['ref' => $bond->invoice_no, 'date' => substr((string) $bond->bond_date, 0, 10), 'due' => 1,
                    'paid' => $joinPaid, 'rate' => $joinRate, 'at' => $inv->created_at ?? $bond->created_at];
            }
            foreach (RdEntry::where('bond_id', $bond->id)->whereDate('paid_on', '>=', $since)->orderBy('id')->get() as $e) {
                $payments[] = ['ref' => 'RD-' . $e->id, 'date' => substr((string) $e->paid_on, 0, 10), 'due' => (int) $e->due_count,
                    'paid' => (float) $e->value, 'rate' => $this->rateAt($e->created_at), 'at' => $e->created_at];
            }

            foreach ($payments as $p) {
                $p100 = $price($p['rate']);
                $goldDue = 0.0;
                $note = '';
                if ($code === self::PLUS1) {
                    $rule = round($pieces * $p100, 2);
                    $goldDue = round($pieces * 0.1, 4);
                    $note = $pieces . ' × 100 mg' . ($pieces < 1 ? ' — paid less than one piece' : '');
                } elseif ($code === self::PLUS2) {
                    if ($p['due'] === 1) {
                        $rule = (float) (intdiv((int) floor($p['paid']), 1500) * 1500);
                        $note = $rule < 1500 ? 'below the ₹1,500 minimum' : 'joining, ₹1,500 multiples';
                    } elseif ($p['due'] === 2) {
                        $rule = round($pieces * $p100, 2);
                        $goldDue = round($pieces * 0.1, 4);
                        $note = "month 2: {$pieces} × 100 mg price";
                    } else {
                        $rule = $joinPaid;
                        $note = 'joining amount';
                    }
                } else {
                    if ($p['due'] === 1) {
                        $rule = $p['paid'] < 1000 ? 0.0 : (float) (intdiv((int) floor($p['paid']), 500) * 500);
                        $note = $rule <= 0 ? 'below the ₹1,000 minimum' : 'joining, ₹500 steps';
                    } else {
                        $rule = $joinPaid;
                        $months = $joinPaid > 0 ? $p['paid'] / $joinPaid : 0;
                        $note = $months > 1.001 && abs($months - round($months)) < 0.001
                            ? (int) round($months) . ' months paid in one entry' : 'joining amount';
                        if ($months > 1.001 && abs($months - round($months)) < 0.001) {
                            $rule = $p['paid'];
                        }
                    }
                }
                $given = $qrOn($p['at']);
                $gGrams = round((float) $given->sum('gram_worth'), 4);
                $gWorth = round((float) $given->sum('cash_worth'), 2);
                $diff = round($p['paid'] - $rule, 2);
                $goldDiff = round($gGrams - $goldDue, 4);
                if ($p['rate'] <= 0 && in_array($code, [self::PLUS1, self::PLUS2], true)) {
                    $note .= ' — NO GOLD RATE for this day (use --gold=)';
                }

                $rows[] = [
                    'Scheme' => $code, 'Bond' => $bond->id, 'Billed on' => $isNew ? 'new system' : 'legacy',
                    'Distributor' => $bond->member?->member_code, 'Name' => $bond->member?->name,
                    'Branch' => $bond->branch?->name, 'Reference' => $p['ref'], 'Date' => $p['date'], 'Due #' => $p['due'],
                    'Paid ₹' => $p['paid'], 'Gold rate' => $p['rate'], '100 mg price ₹' => $p100, 'Rule amount ₹' => $rule,
                    'Excess(+)/Short(-) ₹' => $diff, 'Gold QR given g' => $gGrams, 'Gold QR worth ₹' => $gWorth,
                    'Gold due g' => $goldDue, 'Gold over(+)/short(-) g' => $goldDiff, 'Rule' => $note,
                ];
                $t = &$tot[$code];
                $t ??= ['n' => 0, 'paid' => 0.0, 'rule' => 0.0, 'excess' => 0.0, 'short' => 0.0, 'wrong' => 0, 'given' => 0.0, 'due' => 0.0];
                $t['n']++;
                $t['paid'] += $p['paid'];
                $t['rule'] += $rule;
                $t[$diff >= 0 ? 'excess' : 'short'] += $diff;
                $t['wrong'] += (abs($diff) >= 0.01 || abs($goldDiff) >= 0.0001) ? 1 : 0;
                $t['given'] += $gGrams;
                $t['due'] += $goldDue;
                unset($t);
            }
        }

        if (! $rows) {
            $this->info("No Gold Saving bill or renewal since {$since}.");

            return self::SUCCESS;
        }

        $m = fn (float $v) => number_format($v, 2);
        $off = array_values(array_filter($rows, fn ($r) => abs($r['Excess(+)/Short(-) ₹']) >= 0.01 || abs($r['Gold over(+)/short(-) g']) >= 0.0001));
        $this->table(['Scheme', 'Bond', 'Distributor', 'Reference', 'Date', 'Due', 'Paid', '100 mg', 'Rule amount', 'Excess/Short', 'Gold given g', 'Gold due g', 'Rule'],
            array_map(fn ($r) => [$r['Scheme'], $r['Bond'], $r['Distributor'], $r['Reference'], $r['Date'], $r['Due #'], $m($r['Paid ₹']),
                $m($r['100 mg price ₹']), $m($r['Rule amount ₹']), $m($r['Excess(+)/Short(-) ₹']), $r['Gold QR given g'], $r['Gold due g'], $r['Rule']],
                array_slice($off, 0, 60)));
        if (count($off) > 60) {
            $this->comment('… ' . (count($off) - 60) . ' more — use --csv for the full list.');
        }

        ksort($tot);
        $this->table(['Scheme', 'Payments', 'Not as per rule', 'Paid ₹', 'Rule amount ₹', 'Excess paid ₹', 'Short paid ₹', 'Gold given g', 'Gold due g', 'Gold short g'],
            collect($tot)->map(fn ($t, $c) => [$c, $t['n'], $t['wrong'], $m($t['paid']), $m($t['rule']), $m($t['excess']), $m($t['short']),
                round($t['given'], 4), round($t['due'], 4), round($t['due'] - $t['given'], 4)])->values()->all());
        $this->info(sprintf('READ-ONLY: %d Gold Saving payment(s) since %s checked; %d differ from the board rule. Nothing was changed.', count($rows), $since, count($off)));

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

    /** Gold rate in force at a moment (the rate table), else the --gold option. */
    protected function rateAt($at): float
    {
        $rate = $at ? (float) (LiveRate::where('country', 'IN')->where('effective_at', '<=', $at)->latest('effective_at')->value('gold') ?? 0) : 0.0;

        return $rate > 0 ? $rate : (float) $this->option('gold');
    }
}
