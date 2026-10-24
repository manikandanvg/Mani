<?php

namespace App\Support\Accounts;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\CatalogProduct;
use App\Models\CommissionLedger;
use App\Models\LiveRate;
use App\Models\MemberWallet;
use App\Models\Payslip;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\RedemptionInvoice;
use App\Models\SaleLine;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Models\WalletWithdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The new system's own tables. Stock keeps PIECES per catalog product (rupees for the
 * cash product) with last_rate = cost per piece; sale lines carry qty (pieces) and rate
 * per gram; purchase lines carry weight (grams) and rate per gram, line_total incl. GST.
 */
class NextAccountsSource implements AccountsSource
{
    /** catalog id → [name, material, gram_each]. */
    protected ?array $products = null;

    public function key(): string
    {
        return self::NEXT;
    }

    public function label(): string
    {
        return __('New system (lordicl-next)');
    }

    public function firstDate(): ?Carbon
    {
        $first = SalesInvoice::min('date');

        return $first ? Carbon::parse($first) : null;
    }

    public function branchNames(): array
    {
        return Branch::orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    protected function products(): array
    {
        return $this->products ??= CatalogProduct::withTrashed()->get()->mapWithKeys(function (CatalogProduct $p) {
            $name = is_array($p->name) ? ($p->name['en'] ?? (string) reset($p->name)) : (string) $p->name;
            $gramEach = (float) $p->default_weight > 0 ? (float) $p->default_weight : (MetalUnits::gramsFromName($name) ?? 0.0);

            return [$p->id => ['name' => $name, 'material' => MetalUnits::metal($p->material), 'gram_each' => $gramEach]];
        })->all();
    }

    protected function product(int $id): array
    {
        return $this->products()[$id] ?? ['name' => '#'.$id, 'material' => 'other', 'gram_each' => 0.0];
    }

    public function stockItems(?int $branch): Collection
    {
        $branches = $this->branchNames();

        return Stock::query()->where('quantity', '>', 0)
            ->whereIn('catalog_product_id', collect($this->products())->filter(fn ($p) => in_array($p['material'], MetalUnits::METALS))->keys())
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('catalog_product_id')->orderBy('branch_id')->get()
            ->map(function (Stock $s) use ($branches) {
                $p = $this->product((int) $s->catalog_product_id);
                $pieces = (float) $s->quantity;
                $gross = round($pieces * $p['gram_each'], 4);
                $cost = round($pieces * (float) $s->last_rate, 2);

                return (object) [
                    'metal' => $p['material'],
                    'kind' => MetalUnits::kind($p['name']),
                    'item' => $p['name'],
                    'description' => $s->label ?: ('Stock #'.$s->id),
                    'purity' => (string) $s->purity,
                    'pieces' => $pieces,
                    'gram_each' => $p['gram_each'],
                    'gross_wt' => $gross,
                    'stone_wt' => 0.0,
                    'net_wt' => $gross,
                    'cost_rate' => $p['gram_each'] > 0 ? round((float) $s->last_rate / $p['gram_each'], 2) : 0.0,
                    'cost_value' => $cost,
                    'location' => $branches[$s->branch_id] ?? ('#'.$s->branch_id),
                ];
            })->sortBy([['metal', 'asc'], ['item', 'asc']])->values();
    }

    public function cashInHand(?int $branch): float
    {
        $cashIds = collect($this->products())->filter(fn ($p) => $p['material'] === 'cash')->keys();
        $stockCash = (float) Stock::whereIn('catalog_product_id', $cashIds)->when($branch, fn ($q) => $q->where('branch_id', $branch))->sum('quantity');
        $wallet = (float) Branch::when($branch, fn ($q) => $q->where('id', $branch))->sum('digi_cash_balance');

        return round($stockCash + $wallet, 2);
    }

    public function salesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        return SaleLine::query()->join('sales_invoices as i', 'i.id', '=', 'sale_lines.invoice_id')
            ->whereBetween('i.date', [$from->toDateString(), $to->toDateString()])
            ->when($branch, fn ($q) => $q->where('i.branch_id', $branch))
            ->groupBy('sale_lines.catalog_product_id', 'sale_lines.material')
            ->get(['sale_lines.catalog_product_id', 'sale_lines.material', DB::raw('SUM(sale_lines.qty) AS pcs'),
                DB::raw('SUM(sale_lines.qty * sale_lines.rate) AS pcs_rate'), DB::raw('SUM(sale_lines.line_total) AS taxable')])
            ->map(function ($r) {
                $p = $this->product((int) $r->catalog_product_id);
                $metal = MetalUnits::metal($r->material ?: $p['material']);
                $taxable = (float) $r->taxable;
                if ($metal === 'cash') {
                    return (object) ['metal' => 'cash', 'kind' => 'cash', 'item' => $p['name'], 'pieces' => (float) $r->pcs,
                        'grams' => 0.0, 'metal_value' => $taxable, 'charges' => 0.0, 'taxable' => $taxable];
                }
                // rate is per gram when the product has a known weight; otherwise it is per piece.
                $metalValue = round($p['gram_each'] > 0 ? (float) $r->pcs_rate * $p['gram_each'] : (float) $r->pcs_rate, 2);
                $metalValue = min($metalValue, $taxable) ?: $taxable;

                return (object) [
                    'metal' => $metal,
                    'kind' => MetalUnits::kind($p['name']),
                    'item' => $p['name'],
                    'pieces' => (float) $r->pcs,
                    'grams' => round((float) $r->pcs * $p['gram_each'], 4),
                    'metal_value' => $metalValue,
                    'charges' => round($taxable - $metalValue, 2),
                    'taxable' => $taxable,
                ];
            })->values();
    }

    public function metalFlows(string $metal, Carbon $from, Carbon $to, ?int $branch): Collection
    {
        $window = [$from->toDateString(), $to->toDateString()];
        $ids = collect($this->products())->filter(fn ($p) => $p['material'] === $metal)->keys();

        $issues = SaleLine::query()->join('sales_invoices as i', 'i.id', '=', 'sale_lines.invoice_id')
            ->whereBetween('i.date', $window)->whereIn('sale_lines.catalog_product_id', $ids)
            ->when($branch, fn ($q) => $q->where('i.branch_id', $branch))
            ->groupBy('i.date', 'sale_lines.catalog_product_id')
            ->get(['i.date as d', 'sale_lines.catalog_product_id as pid', DB::raw('SUM(sale_lines.qty) AS pcs'), DB::raw('SUM(sale_lines.qty * sale_lines.rate) AS pcs_rate')])
            ->map(function ($r) {
                $p = $this->product((int) $r->pid);

                return (object) ['d' => $r->d, 'g' => (float) $r->pcs * $p['gram_each'],
                    'v' => $p['gram_each'] > 0 ? (float) $r->pcs_rate * $p['gram_each'] : (float) $r->pcs_rate];
            });

        $receipts = PurchaseLine::query()->join('purchases as p', 'p.id', '=', 'purchase_lines.purchase_id')
            ->whereNull('p.deleted_at')->whereBetween('p.purchase_date', $window)->where('purchase_lines.material', $metal)
            ->when($branch, fn ($q) => $q->where('p.branch_id', $branch))
            ->groupBy('p.purchase_date')
            ->get(['p.purchase_date as d', DB::raw('SUM(purchase_lines.weight) AS g'), DB::raw('SUM(purchase_lines.weight * purchase_lines.rate) AS v')]);

        return LegacyAccountsSource::mergeFlows($receipts, $issues);
    }

    public function invoiceTotals(Carbon $from, Carbon $to, ?int $branch): array
    {
        $window = [$from->toDateString(), $to->toDateString()];
        $s = SalesInvoice::whereBetween('date', $window)->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(net_total) AS taxable'), DB::raw('SUM(cgst) AS cgst'),
                DB::raw('SUM(sgst) AS sgst'), DB::raw('SUM(grand_total) AS grand'), DB::raw('SUM(received) AS received')]);
        $r = RedemptionInvoice::whereBetween('invoice_date', $window)->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(taxable_total) AS taxable'), DB::raw('SUM(cgst) AS cgst'),
                DB::raw('SUM(sgst) AS sgst'), DB::raw('SUM(grand_total) AS grand')]);

        return [
            'count' => (int) $s->n + (int) $r->n,
            'taxable' => (float) $s->taxable + (float) $r->taxable,
            'cgst' => (float) $s->cgst + (float) $r->cgst,
            'sgst' => (float) $s->sgst + (float) $r->sgst,
            'grand' => (float) $s->grand + (float) $r->grand,
            'received' => (float) $s->received + (float) $r->grand,
        ];
    }

    public function receivables(Carbon $asOf, ?int $branch): Collection
    {
        return SalesInvoice::with('branch')->whereDate('date', '<=', $asOf->toDateString())
            ->whereColumn('received', '<', 'grand_total')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('date')->limit(5000)->get()
            ->map(fn (SalesInvoice $i) => (object) [
                'date' => Carbon::parse($i->date),
                'party' => (string) $i->customer_name,
                'invoice_no' => (string) $i->invoice_no,
                'description' => 'Sales invoice · '.($i->branch?->name ?? ''),
                'debit' => (float) $i->grand_total,
                'credit' => (float) $i->received,
                'balance' => round((float) $i->grand_total - (float) $i->received, 2),
                'due' => null,
                'status' => 'Outstanding',
            ]);
    }

    public function dealerReceivables(Carbon $asOf, ?int $branch): float
    {
        return (float) BranchOrderRequest::whereNotIn('status', ['pending', 'rejected', 'cancelled'])
            ->whereDate('created_at', '<=', $asOf->toDateString())
            ->whereColumn('paid_amount', '<', 'grand_total')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->sum(DB::raw('grand_total - paid_amount'));
    }

    public function purchases(Carbon $asOf, ?int $branch): Collection
    {
        return Purchase::with('vendor')->whereDate('purchase_date', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('purchase_date')->orderBy('id')->get()
            ->map(function (Purchase $p) {
                $grand = (float) $p->grand_total;
                $paid = $grand;   // purchases are recorded as cash / online / cheque — the new system keeps no credit purchases

                return (object) [
                    'date' => Carbon::parse($p->purchase_date),
                    'bill_no' => (string) $p->ref_no,
                    'supplier' => (string) ($p->vendor?->name ?? '—'),
                    'description' => trim('Purchase · '.$p->payment_type.' '.$p->notes),
                    'gross' => (float) $p->gross_total,
                    'gst' => (float) $p->gst_total,
                    'grand' => $grand,
                    'paid' => $paid,
                    'balance' => round($grand - $paid, 2),
                    'status' => $paid >= $grand ? 'Paid' : 'Outstanding',
                ];
            });
    }

    public function purchaseLinesByMetal(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        return PurchaseLine::query()->join('purchases as p', 'p.id', '=', 'purchase_lines.purchase_id')
            ->whereNull('p.deleted_at')->whereBetween('p.purchase_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('purchase_lines.material', MetalUnits::METALS)
            ->when($branch, fn ($q) => $q->where('p.branch_id', $branch))
            ->groupBy('purchase_lines.material')
            ->get(['purchase_lines.material', DB::raw('SUM(purchase_lines.weight) AS g'), DB::raw('SUM(purchase_lines.weight * purchase_lines.rate) AS v')])
            ->map(fn ($r) => (object) ['metal' => MetalUnits::metal($r->material), 'grams' => (float) $r->g, 'value' => (float) $r->v]);
    }

    public function schemeCollections(Carbon $asOf, ?int $branch): array
    {
        $bonds = Bond::where('status', 'active')->whereDate('bond_date', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(value) AS v')]);
        $installments = RdEntry::whereDate('paid_on', '<=', $asOf->toDateString())
            ->whereIn('bond_id', fn ($q) => $q->select('id')->from('bonds')->where('status', 'active'))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->sum('value');

        return ['contracts' => (int) $bonds->n, 'contract_value' => (float) $bonds->v, 'installments' => (float) $installments];
    }

    public function pendingQrs(Carbon $asOf, ?int $branch): array
    {
        $r = RedeemableQr::where('status', 'pending')->whereDate('created_at', '<=', $asOf->toDateString())
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(gram_worth) AS g'), DB::raw('SUM(cash_worth) AS v')]);

        return ['count' => (int) $r->n, 'grams' => (float) $r->g, 'value' => (float) $r->v];
    }

    public function memberPayables(Carbon $asOf, ?int $branch): array
    {
        return [
            'Distributor cash wallets' => (float) MemberWallet::sum('cash_balance'),
            'Distributor coupon / e-pin wallets' => (float) MemberWallet::sum(DB::raw('coupon_balance + epin_balance')),
            'Commissions approved / pending payout' => (float) CommissionLedger::whereIn('status', ['pending', 'approved'])
                ->whereDate('earned_on', '<=', $asOf->toDateString())->sum(DB::raw('COALESCE(net_amount, amount)')),
            'Pending wallet withdrawals' => (float) WalletWithdrawal::where('status', 'pending')
                ->whereDate('created_at', '<=', $asOf->toDateString())->sum('amount'),
        ];
    }

    public function expenses(Carbon $from, Carbon $to): Collection
    {
        return collect();   // no expense register in the new system yet
    }

    public function salaries(Carbon $from, Carbon $to): float
    {
        $start = $from->year * 100 + $from->month;
        $end = $to->year * 100 + $to->month;

        return (float) Payslip::query()->join('payroll_runs as r', 'r.id', '=', 'payslips.payroll_run_id')
            ->whereRaw('(r.period_year * 100 + r.period_month) BETWEEN ? AND ?', [$start, $end])
            ->sum('payslips.gross');
    }

    public function commissions(Carbon $from, Carbon $to): ?float
    {
        return (float) CommissionLedger::where('status', 'paid')
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->sum(DB::raw('COALESCE(net_amount, amount)'));
    }

    public function liveRates(): array
    {
        $r = LiveRate::latestFor('IN');

        return ['gold' => (float) ($r->gold ?? 0), 'silver' => (float) ($r->silver ?? 0)];
    }

    public function notes(): array
    {
        return [
            'Source: the new system (lordicl-next). Stock is read from the stock table as it stands now (pieces × last cost per piece); there is no dated stock snapshot.',
            'Cash in Hand = the cash product in stock (rupees) plus branch Digi cash wallets.',
            'Running contracts (bonds) plus their RD installments are shown as Gold Saving Scheme / Customer Collections; pending redeemable QRs as Customer Advances.',
            'Every purchase bill is recorded as paid (cash / online / cheque) — the new system keeps no credit purchases, so Sundry Creditors is nil from this source. Approved dealer orders not fully paid are Dealer / Branch Receivables.',
            'Distributor commissions paid in the period (commission ledger, status paid) are shown as an operating expense; approved / pending ones as Other Payables.',
            'Payroll (payslips gross) fills Employee Salaries; there is no expense register, so the other expense lines come from the manual inputs.',
        ];
    }
}
