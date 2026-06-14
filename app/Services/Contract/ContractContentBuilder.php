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
        $rec = (float) ($bond->epin_value ?: $bond->value);
        $cbc = (float) $bond->cbc_value;                 // monthly cashback value
        $start = $bond->bond_date ? $bond->bond_date->copy() : Carbon::now();

        $tds = ($cbc / 2) * 0.20;
        $sav = ($cbc / 2) - $tds;

        return match (true) {
            in_array($code, [200, 209, 210], true) => $this->savings($rec, $start, $code === 200 ? 3 : ($code === 209 ? 2 : 1)),
            $code === 201 => $this->dealership201($rec, $cbc, $sav, $tds),
            $code === 205 => $this->dealership205($rec, $cbc, $sav, $tds),
            $code === 202 => $this->dealership202($rec, $cbc, $sav, $tds),
            in_array($code, [203, 204, 208], true) => $this->dealershipSplit($rec, match ($code) { 203 => '5,00,000.00', 204 => '25,00,000.00', default => '1,00,00,000.00' }),
            default => '',   // 206 / 212 etc. — header + signature only
        };
    }

    // --- 200 / 209 / 210: gold savings + monthly chart ---
    private function savings(float $rec, Carbon $start, int $bonusMonths): string
    {
        $h = $this->rows([
            ['Savings Amount: ' . $this->m($rec) . '/- x 11 months', $this->m($rec * 11)],
            [$bonusMonths . ' months Bonus', $this->m($rec * $bonusMonths)],
        ]);

        $h .= '<h3 style="text-align:center;color:' . self::C . '">Monthly Chart</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:10px">'
            . '<tr style="background:#faf6ee"><td style="' . $this->cell() . 'font-weight:700">Month</td>'
            . '<td style="' . $this->cell() . 'font-weight:700">Date</td>'
            . '<td style="' . $this->cell() . 'font-weight:700">Status</td></tr>'
            . '<tr><td style="' . $this->cell() . '">1</td><td style="' . $this->cell() . '">' . $start->format('d/m/Y') . '</td>'
            . '<td style="' . $this->cell() . 'color:#16a34a">PAID</td></tr>';

        $d = $start->copy();
        for ($i = 2; $i <= 12; $i++) {
            $d = $d->copy()->addMonthNoOverflow()->day(10);
            $h .= '<tr><td style="' . $this->cell() . '">' . $i . '</td><td style="' . $this->cell() . '">' . $d->format('d/m/Y') . '</td>'
                . '<td style="' . $this->cell() . '">-</td></tr>';
        }

        return $h . '</table>';
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

    // ---------- presentation helpers ----------

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
