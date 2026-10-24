<?php

namespace App\Support\Accounts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the Balance Sheet Report reads the books from: the legacy Lord DB (`lordicl`)
 * or the new system's own tables. Every method returns plain figures / plain objects
 * so the page can lay them out in the auditor's workbook format without caring which
 * book they came from. Nothing here writes.
 *
 * Money is always INR base. Weights are grams. "asOf" figures are balances on a date;
 * "from/to" figures are movements inside a window (both ends inclusive).
 */
interface AccountsSource
{
    public const LEGACY = 'legacy';

    public const NEXT = 'next';

    public function key(): string;

    public function label(): string;

    /** Earliest sales invoice on record (default From date). */
    public function firstDate(): ?Carbon;

    /** Branch id → name, for the branch filter and the register's Location column. */
    public function branchNames(): array;

    /**
     * Metal stock as the book holds it NOW (neither book keeps a dated stock snapshot).
     * Each item: metal (gold|silver), kind (bullion|jewellery), item, description, purity,
     * pieces, gram_each, gross_wt, stone_wt, net_wt, cost_rate (₹/g), cost_value (₹), location.
     */
    public function stockItems(?int $branch): Collection;

    /** Cash held per the book now (the Lord book keeps branch cash as a CASH stock product). */
    public function cashInHand(?int $branch): float;

    /**
     * Metal sales in the window, one row per product: metal, kind, item, pieces, grams,
     * metal_value (metal portion), charges (making + wastage), taxable (metal + charges, ex-GST).
     * Scheme / contract money (the CASH product) comes back as metal 'cash' so the page can keep it out of revenue.
     */
    public function salesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection;

    /**
     * Daily metal movements for the weight ledgers: date (Y-m-d), receipts_g, receipts_value
     * (purchase cost), issues_g, issues_value (metal value at the day's sale rate).
     */
    public function metalFlows(string $metal, Carbon $from, Carbon $to, ?int $branch): Collection;

    /** Output-tax invoices in the window: count, taxable, cgst, sgst, grand, received. */
    public function invoiceTotals(Carbon $from, Carbon $to, ?int $branch): array;

    /** Customer invoices still unpaid on the date: date, party, invoice_no, description, debit, credit, balance, due, status. */
    public function receivables(Carbon $asOf, ?int $branch): Collection;

    /** Credit sales to dealers / branches still unpaid on the date. */
    public function dealerReceivables(Carbon $asOf, ?int $branch): float;

    /** Every purchase up to the date: date (Carbon), bill_no, supplier, description, gross, gst, grand, paid, balance, status. */
    public function purchases(Carbon $asOf, ?int $branch): Collection;

    /** Metal bought in the window: metal, grams, value (metal cost, ex-GST). */
    public function purchaseLinesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection;

    /** Scheme money held on the date: contracts, contract_value, installments. */
    public function schemeCollections(Carbon $asOf, ?int $branch): array;

    /** Gold QRs allotted but not yet delivered on the date: count, grams, value. */
    public function pendingQrs(Carbon $asOf, ?int $branch): array;

    /** Amounts owed to distributors on the date: label → ₹. */
    public function memberPayables(Carbon $asOf, ?int $branch): array;

    /** Expenses booked in the window: date, title, type, amount, mode, remarks. */
    public function expenses(Carbon $from, Carbon $to): Collection;

    /** Payroll cost in the window (gross). */
    public function salaries(Carbon $from, Carbon $to): float;

    /** Distributor commissions paid in the window; null when the book cannot say. */
    public function commissions(Carbon $from, Carbon $to): ?float;

    /** Current rates per gram: gold, silver. */
    public function liveRates(): array;

    /** Source-specific caveats for the auditor's notes. */
    public function notes(): array;
}
