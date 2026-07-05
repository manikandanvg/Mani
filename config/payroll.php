<?php

/*
 * Payroll statutory configuration (2026-07 board/auditor mandate).
 *
 * TDS modes:
 *  - 'flat': gross × tds_pct (profile override, else the TBP stage default) — the
 *    board's simple "lower ranking categories" rule. DEFAULT.
 *  - 'slab': Sec-192-style monthly deduction — the employee's contracted annual
 *    salary (monthly × 12, less the standard deduction) is taxed on the slabs
 *    below, the Sec-87A rebate and cess applied, and 1/12th of that is withheld,
 *    prorated by the month's attendance. The rates ship as the FY 2025-26 new
 *    regime; update here when the Finance Act changes.
 *
 * Whichever mode runs is frozen onto each payslip's snapshot.
 */
return [

    'tds_mode' => env('PAYROLL_TDS_MODE', 'flat'),   // flat | slab

    'standard_deduction' => 75000,

    // New-regime slabs: [upper bound (null = no cap), rate %].
    'slabs' => [
        [400000, 0],
        [800000, 5],
        [1200000, 10],
        [1600000, 15],
        [2000000, 20],
        [2400000, 25],
        [null, 30],
    ],

    // Sec 87A: taxable income up to this → tax fully rebated.
    'rebate_limit' => 1200000,

    'cess_pct' => 4,
];
