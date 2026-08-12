<?php

namespace App\Services\Contract;

use App\Models\Bond;
use Illuminate\Support\Carbon;

/**
 * Builds the per-plan contract body (savings + monthly chart, dealership income, income
 * break-up) — a faithful port of the legacy Lscript::getforcont plan branches, computed
 * from the bond's actual values. Returns an HTML fragment that the contract PDF embeds
 * under CONTRACT DETAILS. Brand colour #ab222f.
 *
 * Mapping (legacy -> new):
 *   recamount  -> bond.epin_value (grand/received)   cbcvalue -> bond.cbc_value (monthly)
 *   planid     -> numeric part of plan.code (P202 -> 202)
 */
class ContractContentBuilder
{
    private const C = '#ab222f';

    public function build(Bond $bond): string
    {
        $plan = $bond->plan;
        $code = (int) preg_replace('/\D/', '', (string) ($plan->code ?? ''));
        // NOTE: epin_value is decimal-cast to a STRING — "0.00" is truthy, so the
        // ?: must run on the float or a zero epin_value renders a ₹0.00 contract.
        $rec = (float) $bond->epin_value ?: (float) $bond->value;
        $cbc = (float) $bond->cbc_value;                 // monthly cashback value
        $start = $bond->bond_date ? $bond->bond_date->copy() : Carbon::now();

        $tds = ($cbc / 2) * 0.20;
        $sav = ($cbc / 2) - $tds;

        // Schema-v2 (2026-07-28) code map: P200 PLUS3 / P208 PLUS2 / P209 PLUS1 savings;
        // P203 Taluka / P204 District / P207 Zonal / P214 Regional dealerships (min from
        // the plan row, not hardcoded). Anything else gets the generic body — a contract
        // must never render empty.
        return match (true) {
            // EVERY RD plan gets the savings body + monthly chart (board 2026-08-10 —
            // the legacy app wrongly keyed this on one plan id; it applies to all RD).
            ($plan->type ?? null) === 'rd' || in_array($code, [200, 208, 209], true)
                => $this->savings($bond, $rec, $start, $code === 200 ? 3 : ($code === 208 ? 2 : 1)),
            $code === 201 => $this->dealership201($rec, $cbc, $sav, $tds),
            $code === 205 => $this->dealership205($rec, $cbc, $sav, $tds),
            $code === 202 => $this->dealership202($rec, $cbc, $sav, $tds),
            in_array($code, [203, 204, 207, 214], true) => $this->dealershipSplit($rec, $this->inr((float) ($plan->min_value ?? 0))),
            default => $this->generic($bond, $rec),
        };
    }

    // --- 200 / 208 / 209: gold savings + monthly chart from the ACTUAL payments ---
    private function savings(Bond $bond, float $rec, Carbon $start, int $bonusMonths): string
    {
        $validity = (int) ($bond->plan->validity_months ?: 11);
        $h = $this->rows([
            ['Savings Amount: ' . $this->m($rec) . '/- x ' . $validity . ' months', $this->m($rec * $validity)],
            [$bonusMonths . ' months Bonus', $this->m($rec * $bonusMonths)],
        ]);

        $h .= '<h3 style="text-align:center;color:' . self::C . '">Monthly Chart</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:10px">'
            . '<tr style="background:#faf6ee"><td style="' . $this->cell() . 'font-weight:700">Month</td>'
            . '<td style="' . $this->cell() . 'font-weight:700">Date</td>'
            . '<td style="' . $this->cell() . 'font-weight:700">Paid</td>'
            . '<td style="' . $this->cell() . 'font-weight:700">Status</td></tr>';

        // Legacy getforcont semantics, generalised to every RD plan: the chart is
        // exactly `validity` rows — ONE per calendar month. Month 1 = the joining
        // payment; each later month SUMS whatever collections landed in it (three
        // renewals in August = one August row for the combined amount), so the chart
        // can never sprawl into duplicate-month rows.
        $entries = $bond->rdEntries()->with('branch')->orderBy('paid_on')->orderBy('id')->get();

        for ($i = 1; $i <= $validity; $i++) {
            $monthStart = $start->copy()->addMonthsNoOverflow($i - 1)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            // Upcoming dues run on the 10TH of each month (legacy "Y-m-10"), not the
            // joining day — month 1 alone shows the actual joining date.
            $scheduled = $monthStart->copy()->day(10);

            $inMonth = $entries->filter(fn ($e) => $e->paid_on
                && $e->paid_on->betweenIncluded($monthStart, $monthEnd));

            // Month 1 = the joining payment, PLUS any renewals taken in that same
            // calendar month (a same-day renewal must not disappear off the chart).
            if ($i === 1) {
                $sum = $rec + (float) $inMonth->sum('value');
                $h .= $this->chartRow(1, $start->format('d/m/Y'), $this->m($sum), 'PAID');

                continue;
            }

            if ($inMonth->isEmpty()) {
                $h .= $this->chartRow($i, $scheduled->format('d/m/Y'), '-', '-');

                continue;
            }

            $sum = (float) $inMonth->sum('value');
            $branches = $inMonth->map(fn ($e) => $e->branch?->name)->filter()->unique()->implode(', ');
            $status = 'PAID ' . $inMonth->first()->paid_on->format('d/m/Y')
                . ($branches !== '' ? ' — ' . e($branches) : '');
            $h .= $this->chartRow($i, $scheduled->format('d/m/Y'), $this->m($sum), $status);
        }

        return $h . '</table>';
    }

    private function chartRow(int $no, string $date, string $amount, string $status): string
    {
        $paid = str_starts_with($status, 'PAID');

        return '<tr><td style="' . $this->cell() . '">' . $no . '</td>'
            . '<td style="' . $this->cell() . '">' . $date . '</td>'
            . '<td style="' . $this->cell() . '">' . $amount . '</td>'
            . '<td style="' . $this->cell() . ($paid ? 'color:#16a34a' : '') . '">' . $status . '</td></tr>';
    }

    // --- 201: SPOT 50% + 24-month 50%, dealership income + break-up ---
    private function dealership201(float $rec, float $cbc, float $sav, float $tds): string
    {
        $h = $this->rows([
            ['SPOT 916 HUID Gold Coins (or) Bar : 50%', $this->m($rec / 2)],
            ['24 months Contract: 50%', $this->m($rec / 2)],
        ]);
        $h .= $this->dealershipIncome([
            'Monthly Self Sales Limit & Profit: 100 grams x Rs.100/- = Rs.10,000/- x 24 months — Total Rs.2,40,000/- + Rs.50,000/- worth Gold in Hand',
            'Monthly Company Group sales profit: Rs.' . $this->n($cbc) . '/- x 24 months (Monthly income Rs.' . $this->n($cbc / 2) . '/- + savings Rs.' . $this->n($sav) . '/- + TDS/SC Rs.' . $this->n($tds) . '/-)',
            'Promotional Incentive : 2% | Bonus Incentive : 1% | AD Onboarding : 5% (BM) | Gold Saving Monthly 10% / renewal 10%',
        ]);

        return $h . $this->incomeBreakup24($cbc, $sav, $tds);
    }

    // --- 205: SPOT 70% + 24-month 30%, dealership income + break-up ---
    private function dealership205(float $rec, float $cbc, float $sav, float $tds): string
    {
        $h = $this->rows([
            ['SPOT 916 HUID Gold Coins (or) Bar : 70.0 %', $this->m($rec * 0.70)],
            ['24 months Contract: 30%', $this->m($rec * 0.30)],
        ]);
        $h .= $this->dealershipIncome([
            'Monthly Self Sales Limit & Profit: 200 grams x Rs.100/- = Rs.20,000/- x 24 months — Total Rs.4,80,000/- + Rs.90,000/- worth Gold in Hand',
            'Monthly Company Group sales profit: Rs.' . $this->n($cbc) . '/- x 24 months (Monthly income Rs.' . $this->n($cbc / 2) . '/- + savings Rs.' . $this->n($sav) . '/- + TDS/SC Rs.' . $this->n($tds) . '/-)',
            'AD Onboarding : 5% (BM) | Retailer Onboarding : 1% (BM) | Gold Saving Monthly 10% / renewal 10%',
        ]);

        return $h . $this->incomeBreakup24($cbc, $sav, $tds);
    }

    // --- 202: 12-month 100%, dealership income + break-up with MC/WC ---
    private function dealership202(float $rec, float $cbc, float $sav, float $tds): string
    {
        $wcmc = $rec * 0.10;
        $h = $this->rows([['12 months Contract: 100%', $this->m($rec)]]);
        $h .= $this->dealershipIncome([
            'Monthly Self Sales Limit & Profit: 200 grams x Rs.100/- = Rs.20,000/- x 12 months — Total Rs.2,40,000/-',
            'Monthly Company sales profit: Rs.' . $this->n($cbc) . '/- x 12 months (Monthly income Rs.' . $this->n($cbc / 2) . '/- + savings Rs.' . $this->n($sav) . '/- + TDS/SC Rs.' . $this->n($tds) . '/-)',
            'Promotional Incentive : 2% | Bonus Incentive : 5.5% | Gold Saving Monthly 10% / renewal 10%',
        ]);

        return $h . $this->breakup([
            ['Monthly income Rs.' . $this->n($cbc / 2) . '/- x 12 months', $this->m($cbc * 6)],
            ['1st Year Gold (Rs.' . $this->n($sav) . '/-x12m=Rs.' . $this->n($sav * 12) . '/- + 3 months Bonus Rs.' . $this->n($tds * 12) . '/- + MC/WC Rs.' . $this->n($wcmc) . ')', $this->m(($sav * 12) + ($tds * 12) + $wcmc)],
            ['TDS Claim (Rs.' . $this->n($tds / 2) . '/-x12 months)', $this->m($tds * 6)],
            ['Total Profit for 12 months', $this->m(($cbc * 6) + ($sav * 12) + ($tds * 12) + ($tds * 6) + $wcmc)],
        ]);
    }

    // --- 203 / 204 / 208: dealership amount split + income list ---
    private function dealershipSplit(float $rec, string $minAmount): string
    {
        $h = $this->rows([
            ['Minimum Dealership Amount', '₹.' . $minAmount],
            ['Your Dealership Amount', $this->m($rec)],
            ['spot Gold 916 HUID Coins (or) Gold Bar (80.0%)', $this->m($rec * 0.80)],
            ['Interior (10.0%)', $this->m($rec * 0.10)],
            ['Refundable Deposit (10.0%)', $this->m($rec * 0.10)],
        ]);

        return $h . $this->dealershipIncome([
            '1. Monthly promotional income: ' . $this->m($rec * 0.02) . '/- (2.00%)',
            '2. Monthly Sales profit: Rs.100/ grams',
            '3. Gold savings Monthly 10% / renewal 10%',
            '4. AD Onboarding 5% (BM)',
            '5. Retailer Onboarding 1% (BM)',
            '6. Wholeseller Onboarding 0.5% (BM)',
        ]);
    }

    // --- shared income break-up table (24-month plans) ---
    private function incomeBreakup24(float $cbc, float $sav, float $tds): string
    {
        return $this->breakup([
            ['Monthly income Rs.' . $this->n($cbc / 2) . '/- x 24 months', $this->m($cbc * 12)],
            ['1st Year Gold (Rs.' . $this->n($sav) . '/-x12m=Rs.' . $this->n($sav * 12) . '/- + Bonus Rs.' . $this->n($tds * 12) . '/-)', $this->m(($sav * 12) + ($tds * 12))],
            ['2nd Year Gold (Rs.' . $this->n($sav) . '/-x12m=Rs.' . $this->n($sav * 12) . '/- + Bonus Rs.' . $this->n($tds * 12) . '/-)', $this->m(($sav * 12) + ($tds * 12))],
            ['TDS Claim (Rs.' . $this->n($tds / 2) . '/-x24 months)', $this->m($tds * 12)],
            ['Total Profit for 24 months', $this->m(($cbc * 12) + ($sav * 24) + ($tds * 24) + ($tds * 12))],
        ]);
    }

    // --- fallback for plans without a bespoke template (e.g. P213 Sub Dealer, P212 G36) ---
    private function generic(Bond $bond, float $rec): string
    {
        $plan = $bond->plan;
        $rows = [
            ['Scheme', e(\App\Support\Translatable::pick($plan->name ?? null) ?: (string) ($plan->code ?? ''))],
            ['Contract Amount', $this->m($rec)],
            ['Validity', (int) ($plan->validity_months ?: 12) . ' months'],
        ];
        if (! empty($plan->settlement)) {
            $rows[] = ['Settlement', e($plan->settlement)];
        }

        return $this->rows($rows);
    }

    // ---------- presentation helpers ----------

    /** Indian-grouped amount, e.g. 2500000 → 25,00,000.00 (lakh/crore digit groups). */
    private function inr(float $n): string
    {
        [$int, $dec] = explode('.', number_format($n, 2, '.', ''));
        if (strlen($int) > 3) {
            $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3));
            $int = $head . ',' . substr($int, -3);
        }

        return $int . '.' . $dec;
    }

    /** Two-column amount rows (label : value). */
    private function rows(array $rows): string
    {
        $h = '<table style="width:100%;font-size:10px;margin-top:8px">';
        foreach ($rows as [$label, $val]) {
            $h .= '<tr><td style="width:60%">' . $label . '</td><td style="width:5%">:</td>'
                . '<td style="border-bottom:1px solid ' . self::C . ';color:' . self::C . ';font-weight:700">' . $val . '</td></tr>';
        }

        return $h . '</table>';
    }

    /** "Dealership Income" bulleted block. */
    private function dealershipIncome(array $lines): string
    {
        $h = '<h3 style="text-align:center;color:' . self::C . '">Dealership Income</h3>'
            . '<table style="width:100%;font-size:10px">';
        foreach ($lines as $line) {
            $h .= '<tr><td style="width:6%;color:' . self::C . '">&#9657;</td>'
                . '<td style="width:94%;border-bottom:1px solid #f0e6cf">' . $line . '</td></tr>';
        }

        return $h . '</table>';
    }

    /** "Income Break-up" table. */
    private function breakup(array $rows): string
    {
        $h = '<h3 style="text-align:center;color:' . self::C . '">Income Break-up</h3>'
            . '<table style="width:100%;font-size:10px">';
        foreach ($rows as [$label, $val]) {
            $h .= '<tr><td style="width:72%">' . $label . '</td><td style="width:5%">:</td>'
                . '<td style="border-bottom:1px solid ' . self::C . ';font-weight:700">' . $val . '</td></tr>';
        }

        return $h . '</table>';
    }

    private function cell(): string
    {
        return 'border:1px solid #ccc;padding:3px;';
    }

    private function m(float $n): string
    {
        return '₹.' . number_format($n, 2);
    }

    private function n(float $n): string
    {
        return number_format($n, 2);
    }
}
