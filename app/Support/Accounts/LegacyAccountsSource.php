<?php

namespace App\Support\Accounts;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The legacy Lord DB (`lordicl`, CodeIgniter) read over the read-only `legacy`
 * connection — the book the auditor will be shown.
 *
 *   tbl_stock          one row per product per branch; weight = PIECES, purprice = cost per
 *                      piece, prdesc (tbl_product) = milligrams per piece. catename CASH rows
 *                      are branch cash (weight = rupees).
 *   tbl_sales_cart     invoice lines; weight = pieces, purprice = metal value per piece at the
 *                      day's rate, aftercharges = line total before GST (metal + making + wastage).
 *                      CASH lines are scheme / contract money, not jewellery revenue.
 *   tbl_salesinvoice   invoice heads (netttotal taxable, sgst / cgst amounts, grandtotal, recamount);
 *   tbl_qrsalesinvoice the same for gold-QR redemption invoices.
 *   tbl_purchase /     supplier bills; tbl_purchase_cart lines (weight = pieces, purprice = cost per piece).
 *   tbl_purchase_cart  transactiontype cashmode = paid; anything else is carried as a creditor.
 *   tbl_branch_sales   HQ → branch sales; non-cash ones are carried as dealer receivables.
 *   tbl_bond /         scheme contracts (epinvalue = money received at start) and their
 *   tbl_rdentry        installments; bondstatus 1 = running.
 *   tbl_digi_queue     gold QRs; PENDING = allotted, not yet delivered (customer advance).
 *   tbl_member         distributor wallets (walletamount, couponwallet, epinwallet).
 *   tbl_digiwithdrawal / tbl_cwwithdrawl   PENDING withdrawals owed to distributors.
 *   tbl_expenses       expense register (empty on the live book so far).
 *
 * The legacy tables carry no useful indexes and store numbers as text; every figure is
 * summed in SQL on a filtered set and joined in PHP. Nothing here writes.
 */
class LegacyAccountsSource implements AccountsSource
{
    protected ?array $branches = null;

    public static function available(): bool
    {
        try {
            DB::connection('legacy')->getPdo();
            DB::connection('legacy')->table('tbl_stock')->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function db(): ConnectionInterface
    {
        return DB::connection('legacy');
    }

    public function key(): string
    {
        return self::LEGACY;
    }

    public function label(): string
    {
        return __('Lord DB (lordicl)');
    }

    public function firstDate(): ?Carbon
    {
        $first = $this->db()->table('tbl_salesinvoice')->where('estdate', '>', '2000-01-01')->min('estdate');

        return $first ? Carbon::parse($first) : null;
    }

    public function branchNames(): array
    {
        return $this->branches ??= $this->db()->table('tbl_branches')->orderBy('storename')->pluck('storename', 'brid')
            ->map(fn ($n) => (string) $n)->all();
    }

    protected function branchName($id): string
    {
        return $this->branchNames()[(int) $id] ?? ('#'.$id);
    }

    public function stockItems(?int $branch): Collection
    {
        return $this->db()->table('tbl_stock as s')->join('tbl_product as p', 'p.prid', '=', 's.prid')
            ->where('s.status', 'ACTIVE')->where('s.weight', '>', 0)->whereIn('s.catename', ['GOLD', 'SILVER'])
            ->when($branch, fn ($q) => $q->where('s.branchid', $branch))
            ->orderBy('s.catename')->orderBy('s.prname')->orderBy('s.branchid')
            ->get(['s.stockid', 's.catename', 's.prname', 's.purity', 's.weight', 's.stoneweight', 's.purprice', 's.branchid', 's.barcode', 's.partno', 'p.prdesc'])
            ->map(function ($r) {
                $gramEach = (float) $r->prdesc > 0 ? (float) $r->prdesc / 1000 : (MetalUnits::gramsFromName($r->prname) ?? 0.0);
                $pieces = (float) $r->weight;
                $gross = round($pieces * $gramEach, 4);
                $stone = (float) $r->stoneweight;
                $cost = round($pieces * (float) $r->purprice, 2);

                return (object) [
                    'metal' => MetalUnits::metal($r->catename),
                    'kind' => MetalUnits::kind($r->prname),
                    'item' => (string) $r->prname,
                    'description' => trim((string) ($r->partno ?: $r->barcode)) ?: ('Stock #'.$r->stockid),
                    'purity' => (string) $r->purity,
                    'pieces' => $pieces,
                    'gram_each' => $gramEach,
                    'gross_wt' => $gross,
                    'stone_wt' => $stone,
                    'net_wt' => max(0, round($gross - $stone, 4)),
                    'cost_rate' => $gramEach > 0 ? round((float) $r->purprice / $gramEach, 2) : 0.0,
                    'cost_value' => $cost,
                    'location' => $this->branchName($r->branchid),
                ];
            });
    }

    public function cashInHand(?int $branch): float
    {
        return (float) $this->db()->table('tbl_stock')->where('status', 'ACTIVE')->where('catename', 'CASH')
            ->when($branch, fn ($q) => $q->where('branchid', $branch))->sum('weight');
    }

    public function salesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        return $this->db()->table('tbl_sales_cart as c')->join('tbl_product as p', 'p.prid', '=', 'c.prid')
            ->where('c.status', 'PASSED')
            ->whereBetween('c.cartaddeddate', [$from->toDateString(), $to->toDateString()])
            ->when($branch, fn ($q) => $q->where('c.branchid', $branch))
            ->groupBy('c.catename', 'c.prid', 'c.prname', 'p.prdesc')
            ->get([
                'c.catename', 'c.prid', 'c.prname', 'p.prdesc',
                DB::raw('SUM(c.weight) AS pcs'),
                DB::raw('SUM(c.purprice * c.weight) AS metal_value'),
                DB::raw('SUM(c.aftercharges) AS taxable'),
            ])
            ->map(function ($r) {
                $metal = MetalUnits::metal($r->catename);
                $gramEach = (float) $r->prdesc > 0 ? (float) $r->prdesc / 1000 : (MetalUnits::gramsFromName($r->prname) ?? 0.0);
                $metalValue = (float) $r->metal_value;
                $taxable = (float) $r->taxable;

                return (object) [
                    'metal' => $metal,
                    'kind' => $metal === 'cash' ? 'cash' : MetalUnits::kind($r->prname),
                    'item' => (string) $r->prname,
                    'pieces' => (float) $r->pcs,
                    'grams' => $metal === 'cash' ? 0.0 : round((float) $r->pcs * $gramEach, 4),
                    'metal_value' => $metalValue,
                    'charges' => $metal === 'cash' ? 0.0 : round($taxable - $metalValue, 2),
                    'taxable' => $taxable,
                ];
            });
    }

    public function metalFlows(string $metal, Carbon $from, Carbon $to, ?int $branch): Collection
    {
        $cate = strtoupper($metal);
        $window = [$from->toDateString(), $to->toDateString()];

        $issues = $this->db()->table('tbl_sales_cart as c')->join('tbl_product as p', 'p.prid', '=', 'c.prid')
            ->where('c.status', 'PASSED')->where('c.catename', $cate)->whereBetween('c.cartaddeddate', $window)
            ->when($branch, fn ($q) => $q->where('c.branchid', $branch))
            ->groupBy('c.cartaddeddate')
            ->get(['c.cartaddeddate as d', DB::raw('SUM(c.weight * p.prdesc / 1000) AS g'), DB::raw('SUM(c.purprice * c.weight) AS v')]);

        $receipts = $this->db()->table('tbl_purchase_cart as c')->join('tbl_product as p', 'p.prid', '=', 'c.prid')
            ->where('c.status', 'ACTIVE')->where('c.catename', $cate)->whereBetween('c.cartaddeddate', $window)
            ->when($branch, fn ($q) => $q->where('c.branchid', $branch))
            ->groupBy('c.cartaddeddate')
            ->get(['c.cartaddeddate as d', DB::raw('SUM(c.weight * p.prdesc / 1000) AS g'), DB::raw('SUM(c.purprice * c.weight) AS v')]);

        return self::mergeFlows($receipts, $issues);
    }

    /** Receipts + issues keyed by day → one row per day, oldest first. */
    public static function mergeFlows(Collection $receipts, Collection $issues): Collection
    {
        $days = [];
        foreach ($receipts as $r) {
            $d = substr((string) $r->d, 0, 10);
            $days[$d]['receipts_g'] = ($days[$d]['receipts_g'] ?? 0) + (float) $r->g;
            $days[$d]['receipts_value'] = ($days[$d]['receipts_value'] ?? 0) + (float) $r->v;
        }
        foreach ($issues as $r) {
            $d = substr((string) $r->d, 0, 10);
            $days[$d]['issues_g'] = ($days[$d]['issues_g'] ?? 0) + (float) $r->g;
            $days[$d]['issues_value'] = ($days[$d]['issues_value'] ?? 0) + (float) $r->v;
        }
        ksort($days);

        return collect($days)->map(fn ($v, $d) => (object) [
            'date' => $d,
            'receipts_g' => round((float) ($v['receipts_g'] ?? 0), 4),
            'receipts_value' => round((float) ($v['receipts_value'] ?? 0), 2),
            'issues_g' => round((float) ($v['issues_g'] ?? 0), 4),
            'issues_value' => round((float) ($v['issues_value'] ?? 0), 2),
        ])->values();
    }

    public function invoiceTotals(Carbon $from, Carbon $to, ?int $branch): array
    {
        $window = [$from->toDateString(), $to->toDateString()];
        $sum = ['count' => 0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'grand' => 0.0, 'received' => 0.0];
        foreach (['tbl_salesinvoice', 'tbl_qrsalesinvoice'] as $table) {
            $r = $this->db()->table($table)->whereBetween('estdate', $window)
                ->when($branch, fn ($q) => $q->where('branchid', $branch))
                ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(netttotal) AS taxable'), DB::raw('SUM(cgst) AS cgst'),
                    DB::raw('SUM(sgst) AS sgst'), DB::raw('SUM(grandtotal) AS grand'), DB::raw('SUM(recamount) AS received')]);
            $sum['count'] += (int) $r->n;
            foreach (['taxable', 'cgst', 'sgst', 'grand', 'received'] as $k) {
                $sum[$k] += (float) $r->{$k};
            }
        }

        return $sum;
    }

    public function receivables(Carbon $asOf, ?int $branch): Collection
    {
        $rows = collect();
        foreach (['tbl_salesinvoice' => 'Sales invoice', 'tbl_qrsalesinvoice' => 'Redemption invoice'] as $table => $label) {
            $rows = $rows->concat($this->db()->table($table)->whereDate('estdate', '<=', $asOf->toDateString())
                ->whereRaw('CAST(grandtotal AS DECIMAL(18,2)) > CAST(recamount AS DECIMAL(18,2))')
                ->when($branch, fn ($q) => $q->where('branchid', $branch))
                ->orderBy('estdate')->limit(5000)->get(['estid', 'estdate', 'cusname', 'grandtotal', 'recamount', 'branchname'])
                ->map(fn ($r) => (object) [
                    'date' => Carbon::parse($r->estdate),
                    'party' => (string) $r->cusname,
                    'invoice_no' => (string) $r->estid,
                    'description' => $label.' · '.$r->branchname,
                    'debit' => (float) $r->grandtotal,
                    'credit' => (float) $r->recamount,
                    'balance' => round((float) $r->grandtotal - (float) $r->recamount, 2),
                    'due' => null,
                    'status' => 'Outstanding',
                ]));
        }

        return $rows->sortBy('date')->values();
    }

    public function dealerReceivables(Carbon $asOf, ?int $branch): float
    {
        return (float) $this->db()->table('tbl_branch_sales')->where('status', 'PASSED')
            ->where('transactiontype', '<>', 'cashmode')->whereDate('podate', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branchid', $branch))->sum('netpurtotal');
    }

    public function purchases(Carbon $asOf, ?int $branch): Collection
    {
        return $this->db()->table('tbl_purchase')->where('status', 'PASSED')->whereDate('podate', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->orderBy('podate')->orderBy('potrid')->get()
            ->map(function ($r) {
                $grand = (float) $r->netpurtotal;
                $paid = strtolower((string) $r->transactiontype) === 'cashmode' ? $grand : 0.0;

                return (object) [
                    'date' => Carbon::parse($r->podate),
                    'bill_no' => (string) $r->pono,
                    'supplier' => (string) $r->supname,
                    'description' => trim('Purchase · '.$r->transactiontype.' '.$r->referance),
                    'gross' => (float) $r->crosstotal,
                    'gst' => (float) $r->gst,
                    'grand' => $grand,
                    'paid' => $paid,
                    'balance' => round($grand - $paid, 2),
                    'status' => $paid >= $grand ? 'Paid' : 'Outstanding',
                ];
            });
    }

    public function purchaseLinesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        return $this->db()->table('tbl_purchase_cart as c')->join('tbl_product as p', 'p.prid', '=', 'c.prid')
            ->where('c.status', 'ACTIVE')->whereIn('c.catename', ['GOLD', 'SILVER'])
            ->whereBetween('c.cartaddeddate', [$from->toDateString(), $to->toDateString()])
            ->when($branch, fn ($q) => $q->where('c.branchid', $branch))
            ->groupBy('c.catename')
            ->get(['c.catename', DB::raw('SUM(c.weight * p.prdesc / 1000) AS g'), DB::raw('SUM(c.purprice * c.weight) AS v')])
            ->map(fn ($r) => (object) ['metal' => MetalUnits::metal($r->catename), 'grams' => (float) $r->g, 'value' => (float) $r->v]);
    }

    public function schemeCollections(Carbon $asOf, ?int $branch): array
    {
        $bonds = $this->db()->table('tbl_bond')->where('bondstatus', 1)->whereDate('bdate', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(epinvalue) AS v')]);

        $installments = $this->db()->table('tbl_rdentry')->whereDate('trdate', '<=', $asOf->toDateString())
            ->whereIn('bondid', fn ($q) => $q->select('bondid')->from('tbl_bond')->where('bondstatus', 1))
            ->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->sum('bvalue');

        return ['contracts' => (int) $bonds->n, 'contract_value' => (float) $bonds->v, 'installments' => (float) $installments];
    }

    public function pendingQrs(Carbon $asOf, ?int $branch): array
    {
        $r = $this->db()->table('tbl_digi_queue')->where('deliverystatus', 'PENDING')->whereDate('dot', '<=', $asOf->toDateString())
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(gm_worth) AS g'), DB::raw('SUM(cash_worth) AS v')]);

        return ['count' => (int) $r->n, 'grams' => (float) $r->g, 'value' => (float) $r->v];
    }

    public function memberPayables(Carbon $asOf, ?int $branch): array
    {
        $wallets = $this->db()->table('tbl_member')
            ->first([DB::raw('SUM(walletamount) AS cash'), DB::raw('SUM(couponwallet) AS coupon'), DB::raw('SUM(epinwallet) AS epin')]);

        return [
            'Distributor cash wallets' => (float) $wallets->cash,
            'Distributor coupon / e-pin wallets' => (float) $wallets->coupon + (float) $wallets->epin,
            'Pending digi-gold withdrawals' => (float) $this->db()->table('tbl_digiwithdrawal')->where('status', 'PENDING')
                ->whereDate('wddate', '<=', $asOf->toDateString())->sum('wdgoldworth'),
            'Pending cash-wallet withdrawals' => (float) $this->db()->table('tbl_cwwithdrawl')->where('status', 'PENDING')
                ->whereDate('wdate', '<=', $asOf->toDateString())->sum('wdamount'),
        ];
    }

    public function expenses(Carbon $from, Carbon $to): Collection
    {
        return $this->db()->table('tbl_expenses')->whereBetween('paydate', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paydate')->limit(5000)->get()
            ->map(fn ($r) => (object) [
                'date' => Carbon::parse($r->paydate),
                'title' => (string) $r->paytitle,
                'type' => (string) $r->exptype,
                'amount' => (float) $r->amount,
                'mode' => (string) $r->mode,
                'remarks' => trim((string) $r->remarks.' '.$r->empname),
            ]);
    }

    public function salaries(Carbon $from, Carbon $to): float
    {
        return (float) $this->db()->table('tbl_expenses')->whereBetween('paydate', [$from->toDateString(), $to->toDateString()])
            ->where('exptype', 'like', '%salar%')->sum('amount');
    }

    public function commissions(Carbon $from, Carbon $to): ?float
    {
        return null;   // the Lord book keeps commissions per bond, not as a paid register
    }

    public function liveRates(): array
    {
        $r = $this->db()->table('tbl_liverate')->first();

        return ['gold' => (float) ($r->gold ?? 0), 'silver' => (float) ($r->silver ?? 0)];
    }

    public function notes(): array
    {
        return [
            'Source: the legacy Lord DB (lordicl). Stock is read from tbl_stock as it stands now (pieces × purchase price); the book keeps no dated stock snapshot.',
            'Branch cash is the CASH product in tbl_stock (weight = rupees) — reported as Cash in Hand.',
            'Sales lines of the CASH product are scheme / contract money and are kept out of jewellery revenue; running contracts (tbl_bond status 1) plus their installments (tbl_rdentry) are shown as Gold Saving Scheme / Customer Collections.',
            'Purchases in cash mode are treated as paid; any other mode is carried as a Sundry Creditor. Non-cash HQ → branch sales are carried as Dealer / Branch Receivables.',
            'Distributor commissions are not kept as a paid register in the Lord book, so the P&L carries no commission line from this source.',
            'tbl_expenses is used for the expense lines; it is empty on the live book, so expenses come from the manual inputs.',
        ];
    }
}
