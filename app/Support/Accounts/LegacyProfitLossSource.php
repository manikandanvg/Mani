<?php

namespace App\Support\Accounts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Lord DB figures the board's Profit & Loss Report needs on top of the balance-sheet
 * reads (board 2026-10-21: scheme-wise incoming, payout classification, stock
 * maintenance, professional statement). Read-only.
 *
 *   tbl_bond            one row per scheme sale: epinvalue = money received at start,
 *                       bvalue = gold-stock allocation, cbcvalue = CBC provision,
 *                       lvlcomone..five = IC provision for the five uplines
 *   tbl_rdentry         RD installments (renewals) per bond
 *   tbl_iccom           Sales Incentive (IC) per bill per upline — status PAID / PASSED = paid, PENDING
 *   tbl_cbc             Promotional Incentive (CBC) gold vouchers per bond, dated on the month they fall
 *                       due — cbcavailabilty PASSED = issued, PENDING = future months
 *   tbl_memberearning_level  GAP / level income per member per run (comname GAP, earnamount gross,
 *                       doe = run date, status PAID / PENDING); 20% TDS deducted before payout
 *   tbl_reseller_com    dealer Billing Margin (comtypeid 1) per bill
 *   tbl_digiwithdrawal  digi-gold withdrawals (gold worth delivered to customers)
 *   tbl_digi_queue      gold QRs: route INVOICE = allotted on a bill (dot), REDEEMED on deliverydate
 */
class LegacyProfitLossSource extends LegacyAccountsSource
{
    /** HQ in the Lord book is branch 1; every other branch row is the dealer network. */
    public const HQ_BRANCH = 1;

    /**
     * Board 2026-10-21: these schemes are 100% HOLDING — the money stays with Lord, nothing is
     * allotted as gold stock at billing (no invoice QR exists for any of their bonds); the gold
     * falls due only at the 12-month settlement, which the Lord book settles offline. Keyed by
     * legacy planid → label shown in the Treatment column.
     */
    public const HOLDING_PLANS = [202 => 'G12 Forward contractors'];

    /** Board 2026-10-21: 20% TDS is deducted from every GAP payout before it is handed over. */
    public const GAP_TDS_PCT = 20.0;

    /** Scheme rules from the legacy plan master (tbl_planjewel), keyed by planid as string. */
    public function planRules(): Collection
    {
        $pcts = fn ($csv) => array_values(array_filter(array_map('floatval', preg_split('/[,\s]+/', trim((string) $csv))), fn ($v) => $v > 0));

        return collect($this->db()->table('tbl_planjewel')->orderBy('planid')->get())->mapWithKeys(function ($p) use ($pcts) {
            $ic = $pcts($p->iccom);
            $level = $pcts($p->levelcom);
            $cbcMonths = (int) $p->cbcount;

            return [(string) $p->planid => (object) [
                'planid' => (string) $p->planid,
                'name' => trim((string) ($p->plannameen ?: $p->planname)),
                'product' => (string) $p->productname,
                'min_value' => (float) $p->minvalue,
                'allocation_pct' => (float) $p->allocation,
                'cbc_pct' => (float) $p->cbvalue,
                'cbc_months' => $cbcMonths,
                'cbc_total_pct' => round((float) $p->cbvalue * max($cbcMonths, 1), 2),
                'ic' => $ic,
                'ic_total_pct' => round(array_sum($ic), 2),
                'level' => $level,
                'level_total_pct' => round(array_sum($level), 2),
                'level_months' => (int) $p->levelcomduration,
                'billing_margin_pct' => (float) $p->billingmargin,
                'gm_margin_pct' => (float) $p->gmmargin,
                'status' => (string) $p->status,
                'is_holding' => isset(self::HOLDING_PLANS[(int) $p->planid]),
            ]];
        });
    }

    protected function window(Carbon $from, Carbon $to): array
    {
        return [$from->toDateString(), $to->toDateString()];
    }

    /** One row per scheme: contracts, invested, installments, allocation, cbc_provision, ic_provision, returns. */
    public function schemeIncoming(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        $bonds = $this->db()->table('tbl_bond')->whereBetween('bdate', $this->window($from, $to))
            ->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->groupBy('planid')
            ->get(['planid', DB::raw('MAX(planname) AS planname'), DB::raw('COUNT(*) AS n'), DB::raw('SUM(epinvalue) AS invested'), DB::raw('SUM(bvalue) AS allocation'),
                DB::raw('SUM(cbcvalue) AS cbc'), DB::raw('SUM(lvlcomone + lvlcomtwo + lvlcomthree + lvlcomfour + lvlcomfive) AS ic'),
                DB::raw('SUM(returnpayment) AS returns'),
                // bondstatus 1 = running (active); 0 = closed by the monthly script once the term elapsed (inactive)
                DB::raw('SUM(CASE WHEN bondstatus = 1 THEN 1 ELSE 0 END) AS active_n'),
                DB::raw('SUM(CASE WHEN bondstatus = 1 THEN epinvalue ELSE 0 END) AS active_invested'),
                DB::raw('SUM(CASE WHEN bondstatus = 1 THEN bvalue ELSE 0 END) AS active_allocation'),
                DB::raw('SUM(CASE WHEN bondstatus <> 1 THEN 1 ELSE 0 END) AS closed_n'),
                DB::raw('SUM(CASE WHEN bondstatus <> 1 THEN epinvalue ELSE 0 END) AS closed_invested'),
                DB::raw('SUM(CASE WHEN bondstatus <> 1 THEN bvalue ELSE 0 END) AS closed_allocation')]);

        // LEFT join: an installment whose bond row is missing still counts, under "(no scheme)".
        $rd = $this->db()->table('tbl_rdentry as r')->leftJoin('tbl_bond as b', 'b.bondid', '=', 'r.bondid')
            ->whereBetween('r.trdate', $this->window($from, $to))
            ->when($branch, fn ($q) => $q->where('r.branchid', $branch))
            ->groupBy(DB::raw("COALESCE(b.planid, '')"))
            ->get([DB::raw("COALESCE(b.planid, '') AS planid"), DB::raw('COUNT(*) AS n'), DB::raw('SUM(r.bvalue) AS v')])->keyBy('planid');

        // English scheme names from the plan master — tbl_bond.planname carries the locale the
        // bill was raised in (Tamil names split one scheme into several rows and DejaVu has no Tamil glyphs).
        $rules = $this->planRules();
        $plans = $rules->map(fn ($r) => $r->name);

        $rows = $bonds->map(function ($b) use ($plans, $rd, $rules) {
            $holding = isset(self::HOLDING_PLANS[(int) $b->planid]);
            $invested = (float) $b->invested;
            $allocation = $holding ? 0.0 : (float) $b->allocation;

            return (object) [
                'planid' => (string) $b->planid,
                'scheme' => ($plans[(string) $b->planid] ?? '') ?: (trim((string) $b->planname) ?: __('(no scheme)')),
                'contracts' => (int) $b->n,
                'invested' => $invested,
                'installments' => (float) ($rd[$b->planid]->v ?? 0),
                'installment_count' => (int) ($rd[$b->planid]->n ?? 0),
                'book_allocation' => (float) $b->allocation,           // bvalue as the book recorded it
                'allocation' => $allocation,                           // what the P&L charges as gold stock
                'holding' => round($invested - $allocation, 2),        // money that stays with Lord
                'is_holding' => $holding,
                'treatment' => $holding ? __('Holding 100% — gold due at 12-month settlement (board)') : __('Gold stock :p / holding :h', ['p' => self::pctText($allocation, $invested), 'h' => self::pctText($invested - $allocation, $invested)]),
                'plan_allocation_pct' => (float) ($rules[(string) $b->planid]->allocation_pct ?? 0),
                'cbc_provision' => (float) $b->cbc,
                'ic_provision' => (float) $b->ic,
                'returns' => (float) $b->returns,
                'active_contracts' => (int) $b->active_n,
                'active_invested' => (float) $b->active_invested,
                'active_allocation' => $holding ? 0.0 : (float) $b->active_allocation,
                'closed_contracts' => (int) $b->closed_n,
                'closed_invested' => (float) $b->closed_invested,
                'closed_allocation' => $holding ? 0.0 : (float) $b->closed_allocation,
            ];
        });

        // Installments on contracts started before the window still belong to their scheme.
        foreach ($rd as $planid => $r) {
            if (! $rows->contains(fn ($row) => $row->planid === (string) $planid)) {
                $rows->push((object) ['planid' => (string) $planid, 'scheme' => ($plans[(string) $planid] ?? '') ?: __('(no scheme)'), 'contracts' => 0,
                    'invested' => 0.0, 'installments' => (float) $r->v, 'installment_count' => (int) $r->n, 'allocation' => 0.0,
                    'book_allocation' => 0.0, 'holding' => 0.0, 'is_holding' => false, 'treatment' => '—', 'plan_allocation_pct' => 0.0,
                    'cbc_provision' => 0.0, 'ic_provision' => 0.0, 'returns' => 0.0,
                    'active_contracts' => 0, 'active_invested' => 0.0, 'active_allocation' => 0.0,
                    'closed_contracts' => 0, 'closed_invested' => 0.0, 'closed_allocation' => 0.0]);
            }
        }

        return $rows->sortByDesc(fn ($r) => $r->invested + $r->installments)->values();
    }

    protected static function pctText(float $part, float $whole): string
    {
        return $whole > 0 ? rtrim(rtrim(number_format($part / $whole * 100, 2), '0'), '.').'%' : '—';
    }

    /** Payout classes for the window. Keys: ic, cbc, gap, billing, digi, qr_issued, qr_redeemed. */
    public function payouts(Carbon $from, Carbon $to, ?int $branch): array
    {
        $w = $this->window($from, $to);

        $ic = $this->db()->table('tbl_iccom')->whereBetween('billdate', $w)
            ->when($branch, fn ($q) => $q->where('billbybranch', $branch))
            ->groupBy('status')->get(['status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(comamount) AS v')])->keyBy(fn ($r) => strtoupper((string) $r->status));

        $cbc = $this->db()->table('tbl_cbc')->whereBetween('cbcdate', $w)
            ->groupBy('cbcavailabilty')->get(['cbcavailabilty AS status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(cbworth) AS v')])->keyBy(fn ($r) => strtoupper((string) $r->status));

        // Board 2026-10-21: GAP income is the member-level register tbl_memberearning_level (comname GAP,
        // earnamount gross, doe = run date); 20% TDS is deducted before payout.
        $gap = $this->db()->table('tbl_memberearning_level')->whereBetween('doe', $w)->whereRaw("UPPER(comname) = 'GAP'")
            ->groupBy('status')->get(['status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(earnamount) AS gross')])->keyBy(fn ($r) => strtoupper((string) $r->status));

        $billing = $this->db()->table('tbl_reseller_com')->where('comtypeid', 1)->whereBetween('billdate', $w)
            ->when($branch, fn ($q) => $q->where('brid', $branch))
            ->groupBy('status')->get(['status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(comvalue) AS v')])->keyBy(fn ($r) => strtoupper((string) $r->status));

        $digi = $this->db()->table('tbl_digiwithdrawal')->whereBetween('wddate', $w)
            ->groupBy('status')->get(['status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(wdgoldworth) AS v'), DB::raw('SUM(wdgoldgm) AS g')])->keyBy(fn ($r) => strtoupper((string) $r->status));

        $issued = $this->db()->table('tbl_digi_queue')->where('route', 'INVOICE')->whereBetween('dot', $w)
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(gm_worth) AS g'), DB::raw('SUM(cash_worth) AS v')]);
        $redeemed = $this->db()->table('tbl_digi_queue')->where('route', 'INVOICE')->where('deliverystatus', 'REDEEMED')->whereBetween('deliverydate', $w)
            ->when($branch, fn ($q) => $q->where('redeembranchid', $branch))
            ->first([DB::raw('COUNT(*) AS n'), DB::raw('SUM(gm_worth) AS g'), DB::raw('SUM(cash_worth) AS v')]);

        $v = fn ($c, $k, $f = 'v') => (float) ($c[$k]->{$f} ?? 0);
        $n = fn ($c, $k) => (int) ($c[$k]->n ?? 0);

        return [
            'ic' => ['paid' => $v($ic, 'PAID') + $v($ic, 'PASSED'), 'pending' => $v($ic, 'PENDING'), 'count' => $n($ic, 'PAID') + $n($ic, 'PASSED') + $n($ic, 'PENDING')],
            'cbc' => ['paid' => $v($cbc, 'PASSED'), 'pending' => $v($cbc, 'PENDING'), 'count' => $n($cbc, 'PASSED') + $n($cbc, 'PENDING')],
            'gap' => [
                'gross' => $gapGross = $v($gap, 'PAID', 'gross') + $v($gap, 'PENDING', 'gross'),
                'tds' => round($gapGross * self::GAP_TDS_PCT / 100, 2),
                'paid' => round($v($gap, 'PAID', 'gross') * (100 - self::GAP_TDS_PCT) / 100, 2),        // net handed over
                'pending' => round($v($gap, 'PENDING', 'gross') * (100 - self::GAP_TDS_PCT) / 100, 2),
                'count' => $n($gap, 'PAID') + $n($gap, 'PENDING'),
            ],
            'billing' => ['paid' => $v($billing, 'PASSED') + $v($billing, 'PAID'), 'pending' => $v($billing, 'PENDING'), 'count' => $billing->sum('n')],
            'digi' => ['paid' => $v($digi, 'PASSED'), 'pending' => $v($digi, 'PENDING'), 'grams' => $v($digi, 'PASSED', 'g') + $v($digi, 'PENDING', 'g'), 'count' => $digi->sum('n')],
            'qr_issued' => ['count' => (int) $issued->n, 'grams' => (float) $issued->g, 'value' => (float) $issued->v],
            'qr_redeemed' => ['count' => (int) $redeemed->n, 'grams' => (float) $redeemed->g, 'value' => (float) $redeemed->v],
        ];
    }

    /**
     * Month (Y-m) → contracts, invested, installments, sales_metal, sales_profit, scheme_sales,
     * ic, cbc, gap_gross, gap_net, billing. Months without rows are absent.
     */
    public function monthly(Carbon $from, Carbon $to, ?int $branch): Collection
    {
        $w = $this->window($from, $to);
        $m = [];
        $put = function ($rows, string $col, string $key) use (&$m) {
            foreach ($rows as $r) {
                $m[$r->m][$key] = ($m[$r->m][$key] ?? 0) + (float) $r->{$col};
            }
        };

        $bonds = $this->db()->table('tbl_bond')->whereBetween('bdate', $w)->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(bdate, '%Y-%m') AS m"), DB::raw('COUNT(*) AS n'), DB::raw('SUM(epinvalue) AS v')]);
        $put($bonds, 'n', 'contracts');
        $put($bonds, 'v', 'invested');

        $put($this->db()->table('tbl_rdentry')->whereBetween('trdate', $w)->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(trdate, '%Y-%m') AS m"), DB::raw('SUM(bvalue) AS v')]), 'v', 'installments');

        $sales = $this->db()->table('tbl_sales_cart')->where('status', 'PASSED')->whereBetween('cartaddeddate', $w)
            ->when($branch, fn ($q) => $q->where('branchid', $branch))
            ->groupBy('m', 'catename')->get([DB::raw("DATE_FORMAT(cartaddeddate, '%Y-%m') AS m"), 'catename',
                DB::raw('SUM(purprice * weight) AS metal'), DB::raw('SUM(aftercharges) AS taxable')]);
        foreach ($sales as $r) {
            if (strtoupper((string) $r->catename) === 'CASH') {
                $m[$r->m]['scheme_sales'] = ($m[$r->m]['scheme_sales'] ?? 0) + (float) $r->taxable;
            } else {
                $m[$r->m]['sales_metal'] = ($m[$r->m]['sales_metal'] ?? 0) + (float) $r->metal;
                $m[$r->m]['sales_profit'] = ($m[$r->m]['sales_profit'] ?? 0) + ((float) $r->taxable - (float) $r->metal);
            }
        }

        $put($this->db()->table('tbl_iccom')->whereBetween('billdate', $w)->when($branch, fn ($q) => $q->where('billbybranch', $branch))
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(billdate, '%Y-%m') AS m"), DB::raw('SUM(comamount) AS v')]), 'v', 'ic');

        $put($this->db()->table('tbl_cbc')->where('cbcavailabilty', 'PASSED')->whereBetween('cbcdate', $w)
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(cbcdate, '%Y-%m') AS m"), DB::raw('SUM(cbworth) AS v')]), 'v', 'cbc');

        $gap = $this->db()->table('tbl_memberearning_level')->whereBetween('doe', $w)->whereRaw("UPPER(comname) = 'GAP'")
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(doe, '%Y-%m') AS m"), DB::raw('SUM(earnamount) AS gross'),
                DB::raw('SUM(earnamount) * '.((100 - self::GAP_TDS_PCT) / 100).' AS net')]);
        $put($gap, 'gross', 'gap_gross');
        $put($gap, 'net', 'gap_net');

        $put($this->db()->table('tbl_reseller_com')->where('comtypeid', 1)->whereBetween('billdate', $w)->when($branch, fn ($q) => $q->where('brid', $branch))
            ->groupBy('m')->get([DB::raw("DATE_FORMAT(billdate, '%Y-%m') AS m"), DB::raw('SUM(comvalue) AS v')]), 'v', 'billing');

        ksort($m);

        return collect($m)->map(fn ($v, $k) => (object) ($v + ['month' => $k]));
    }

    /** One row per branch holding stock: id, name, is_hq, gold_pcs, gold_g, gold_cost, silver_pcs, silver_g, silver_cost, cash. */
    public function stockByBranch(?int $branch): Collection
    {
        $rows = $this->db()->table('tbl_stock as s')->leftJoin('tbl_product as p', 'p.prid', '=', 's.prid')
            ->where('s.status', 'ACTIVE')->where('s.weight', '<>', 0)
            ->when($branch, fn ($q) => $q->where('s.branchid', $branch))
            ->groupBy('s.branchid', 's.catename')
            ->get(['s.branchid', 's.catename', DB::raw('SUM(s.weight) AS pcs'), DB::raw('SUM(s.weight * COALESCE(p.prdesc, 0) / 1000) AS grams'),
                DB::raw('SUM(s.weight * s.purprice) AS cost')]);

        $names = $this->branchNames();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->branchid;
            $out[$id] ??= ['id' => $id, 'name' => $names[$id] ?? ('#'.$id), 'is_hq' => $id === self::HQ_BRANCH,
                'gold_pcs' => 0.0, 'gold_g' => 0.0, 'gold_cost' => 0.0, 'silver_pcs' => 0.0, 'silver_g' => 0.0, 'silver_cost' => 0.0, 'cash' => 0.0];
            switch (strtoupper((string) $r->catename)) {
                case 'GOLD':
                    $out[$id]['gold_pcs'] += (float) $r->pcs;
                    $out[$id]['gold_g'] += (float) $r->grams;
                    $out[$id]['gold_cost'] += (float) $r->cost;
                    break;
                case 'SILVER':
                    $out[$id]['silver_pcs'] += (float) $r->pcs;
                    $out[$id]['silver_g'] += (float) $r->grams;
                    $out[$id]['silver_cost'] += (float) $r->cost;
                    break;
                case 'CASH':
                    $out[$id]['cash'] += (float) $r->pcs;
                    break;
            }
        }

        return collect($out)->map(fn ($r) => (object) $r)
            ->sortBy([['is_hq', 'desc'], ['gold_cost', 'desc']])->values();
    }
}
