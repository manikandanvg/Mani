<?php

namespace App\Services;

use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\MemberWallet;
use App\Models\ResellerCommission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified commission approval — the single gate that releases earnings to a member's
 * wallet. The 7 streams live in three tables (IC/GAP → commission_ledger, CBC →
 * cbc_entries, the 4 distributor margins → reseller_commissions); this service maps a
 * UI "type" to the right source query and credits the correct wallet on approval.
 *
 * Nothing credits a wallet before this runs: records are created 'pending'/'passed';
 * approval marks them paid (+ paid date) and posts the amount to the member wallet.
 */
class CommissionApprovalService
{
    /**
     * CBC split (board 2026-11-01), on the coupon's whole worth — e.g. ₹12,000:
     *   sales profit  50 %  → ₹6,000 to the CASH wallet
     *   TDS + service  5 + 5 % → ₹1,200 withheld
     *   remainder            → ₹4,800 RETAINED by Head Office (recorded on the coupon row as
     *                          coupon_amount; it is NOT posted to any member wallet)
     * The percentages come from Commission Setup (stream CBC: cash_pct, tds, service); these
     * are the defaults. The legacy 40 % EPIN credit at cut-off is retired with the digi wallet.
     * User 2026-11-03: member_wallets.coupon_balance is a SEPARATE, admin-only wallet kept for
     * another purpose — CBC never touches it.
     */
    public const CBC_CASH_PCT = 50.0;

    /** Dealership plans (plans.cbc_dealer_rule): 100 % cash, 10 % TDS, no service — user 2026-11-04. */
    public const CBC_DEALER_CASH_PCT = 100.0;
    public const CBC_DEALER_TDS_PCT = 10.0;
    public const CBC_DEALER_SERVICE_PCT = 0.0;

    /** Withheld on cash income (IC/GAP + the 4 margins) at approval; CBC withholds on the whole coupon. */
    public const TDS_PCT = 5.0;

    public const SERVICE_PCT = 5.0;

    /**
     * UI dropdown — the commission streams in display order.
     * Display names follow the 2026-07 board/auditor terminology: IC is shown as
     * "Sales Incentive" (legacy Sales Profit; board correction 2026-08-27 — CBC is the
     * "Promotional Incentive") and GAP as "Turnover-based
     * Salary" (legacy Level Income). Internal keys are unchanged. RD Renewal Margin
     * (2026-07) was carved out of Gold Margin, which it used to share an id with.
     */
    public const TYPES = [
        'IC' => 'Sales Incentive (IC)',
        'GAP' => 'Turnover-based Salary (GAP)',
        'CBC' => 'Promotional Incentive (CBC)',
        'BILL_MARGIN' => 'Billing Margin',
        'GOLD_MARGIN' => 'Gold Margin',
        'SILVER_MARGIN' => 'Silver Margin',
        'STOCK_TRANSFER_MARGIN' => 'Stock Transfer Margin',
        'RD_RENEWAL_MARGIN' => 'Gold Saving Renewal Margin',
    ];

    /** Board display name of a stream key (unknown keys fall back to a readable form). */
    public static function label(?string $type): string
    {
        $t = strtoupper((string) $type);

        return self::TYPES[$t] ?? match ($t) {
            'RESELLER' => 'Dealer Margins',
            default => ucwords(strtolower(str_replace('_', ' ', $t))),
        };
    }

    /** Stream picker options for the reports (ledger streams + the dealer-margin group). */
    public static function streamOptions(): array
    {
        return [
            'IC' => self::TYPES['IC'],
            'GAP' => self::TYPES['GAP'],
            'CBC' => self::TYPES['CBC'],
            'BILL_MARGIN' => self::TYPES['BILL_MARGIN'],
            'RESELLER' => 'Dealer Margins (gold / silver / stock transfer / renewal)',
        ];
    }

    /** reseller_commissions.com_type_id for each margin type. */
    protected const RESELLER_COM = [
        'BILL_MARGIN' => ResellerCommission::COM_BILL_MARGIN,
        'GOLD_MARGIN' => ResellerCommission::COM_GOLD_MARGIN,
        'SILVER_MARGIN' => ResellerCommission::COM_SILVER_MARGIN,
        'STOCK_TRANSFER_MARGIN' => ResellerCommission::COM_STOCK_TRANSFER_MARGIN,
        'RD_RENEWAL_MARGIN' => ResellerCommission::COM_RD_RENEWAL_MARGIN,
    ];

    /**
     * Un-paid records of one type within a date range, ordered oldest first.
     * Date is matched against each table's "earned" column.
     */
    public function query(string $type, ?string $from = null, ?string $to = null): Builder
    {
        if (in_array($type, ['IC', 'GAP'], true)) {
            return CommissionLedger::query()
                ->with(['member', 'fromMember'])
                ->where('type', $type)
                ->where('status', '!=', 'paid')
                ->when($from, fn ($q) => $q->whereDate('earned_on', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('earned_on', '<=', $to))
                ->orderBy('earned_on');
        }

        if ($type === 'CBC') {
            return CbcEntry::query()
                ->with(['member'])
                ->where('status', 'pending')
                // a coupon is approvable once its month has come — the legacy import carries one
                // pending coupon per bond per FUTURE month up to 2028 (user 2026-11-02)
                ->whereDate('cbc_date', '<=', now()->toDateString())
                ->when($from, fn ($q) => $q->whereDate('cbc_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('cbc_date', '<=', $to))
                ->orderBy('cbc_date');
        }

        $com = self::RESELLER_COM[$type] ?? 1;

        return ResellerCommission::query()
            ->with(['branch', 'referenceMember'])
            ->where('com_type_id', $com)
            ->where('status', '!=', 'paid')
            // legacy history (₹4.78 cr of "passed" rows) was settled in legacy; the dealer's unpaid
            // balance is paid by hand on Commissions → Pending Billing Margin (user 2026-11-02)
            ->where('source', '!=', ResellerCommission::SOURCE_LEGACY)
            ->when($from, fn ($q) => $q->whereDate('bill_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('bill_date', '<=', $to))
            ->orderBy('bill_date');
    }

    /**
     * Beneficiary grouping column of a stream: members earn IC/GAP/CBC, branches the margins.
     */
    public static function groupColumn(string $type): string
    {
        return isset(self::RESELLER_COM[$type]) ? 'branch_id' : 'member_id';
    }

    /**
     * Approve every un-paid row of a stream in a date window (optionally only the given
     * beneficiaries), row by row through approve(), then push ONE acknowledgement per
     * beneficiary. Stops early — returning done=false — once $budgetSeconds of work is used,
     * so a queued job can re-dispatch itself for the rest: user 2026-11-03, the CBC approval of
     * 1 Nov (11,624 coupons, 8,843 members) was killed by the web-server timeout after 88 rows.
     *
     * @param  int[]|null  $beneficiaryIds  member ids (IC/GAP/CBC) or branch ids (margins); null = all
     * @return array{rows:int, gross:float, beneficiaries:int, done:bool}
     */
    public function approveBatch(string $type, ?string $from, ?string $to, ?array $beneficiaryIds = null, ?float $budgetSeconds = null, ?int $by = null): array
    {
        $key = self::groupColumn($type);
        $started = microtime(true);
        $query = $this->query($type, $from, $to)->without(['member', 'fromMember', 'branch', 'referenceMember'])
            ->when($beneficiaryIds !== null, fn ($q) => $q->whereIn($key, $beneficiaryIds));
        $perBeneficiary = [];
        $rows = 0;
        $gross = 0.0;
        $done = true;
        foreach ($query->cursor() as $row) {
            // always make progress: the budget is checked only after at least one row went through
            if ($rows > 0 && $budgetSeconds !== null && microtime(true) - $started > $budgetSeconds) {
                $done = false;
                break;
            }
            if (! $this->approve($row)) {
                continue;
            }
            $amount = (float) ($row instanceof CbcEntry ? $row->worth : ($row instanceof ResellerCommission ? $row->com_value : $row->amount));
            $rows++;
            $gross += $amount;
            $id = (int) $row->{$key};
            // CBC: the cash that reached the wallet is on the row (plan-aware split) — sum it for the ack
            $cash = $row instanceof CbcEntry ? (float) $row->cash_amount : 0.0;
            $perBeneficiary[$id] = ['n' => ($perBeneficiary[$id]['n'] ?? 0) + 1, 'sum' => ($perBeneficiary[$id]['sum'] ?? 0) + $amount, 'cash' => ($perBeneficiary[$id]['cash'] ?? 0) + $cash];
        }

        // acknowledgement (board 2026-08-11: push + inbox) — one per beneficiary, after the money moved.
        // CBC (user 2026-11-03): the coupon worth and the coupon wallet are Head Office figures —
        // the member is told only the cash that reached the wallet.
        foreach ($perBeneficiary as $id => $t) {
            $member = $key === 'member_id' ? \App\Models\Member::find($id) : \App\Models\Branch::find($id)?->distributorUser?->memberAccount;
            $body = $type === 'CBC'
                ? "{$t['n']} promotional incentive coupon" . ($t['n'] === 1 ? '' : 's') . ' approved — ₹' . \App\Support\Money::group((float) ($t['cash'] ?? 0)) . ' credited to your cash wallet.'
                : "{$t['n']} " . self::label($type) . ' entr' . ($t['n'] === 1 ? 'y' : 'ies') . ' approved — gross ₹' . \App\Support\Money::group($t['sum'])
                    . '; the net after TDS & service charge is now in your wallet.';
            \App\Services\Push\Notifier::to($member, 'commission', 'Commission credited to your wallet', $body, route: '/wallet');
        }
        if ($rows > 0) {
            \App\Services\Activity\ActivityLogger::log('commission', 'commissions_approved',
                self::label($type) . ': ' . $rows . ' entries (gross ₹' . \App\Support\Money::group($gross) . ') approved for ' . count($perBeneficiary) . ' beneficiar' . (count($perBeneficiary) === 1 ? 'y' : 'ies') . ($done ? '' : ' — more to follow'),
                ['details' => ['type' => $type, 'from' => $from, 'to' => $to, 'rows' => $rows, 'gross' => round($gross, 2), 'beneficiaries' => count($perBeneficiary), 'done' => $done, 'by' => $by]]);
        }

        return ['rows' => $rows, 'gross' => round($gross, 2), 'beneficiaries' => count($perBeneficiary), 'done' => $done];
    }

    /**
     * Approve one record: mark paid (+ paid date) and credit the beneficiary wallet.
     * Idempotent — returns false if the row was already paid. Wrapped in a transaction.
     */
    public function approve(Model $row, ?string $paidOn = null): bool
    {
        $paidOn ??= Carbon::now()->toDateString();

        return match (true) {
            $row instanceof CommissionLedger => $this->approveLedger($row, $paidOn),
            $row instanceof CbcEntry => $this->approveCbc($row, $paidOn),
            $row instanceof ResellerCommission => $this->approveReseller($row, $paidOn),
            default => false,
        };
    }

    /**
     * Inside the row's transaction: re-read it under a row lock and give up if another run
     * (page job, shell command, cut-off approval) paid it meanwhile — so two runs on the same
     * stream can never credit a wallet twice. Refreshes $row with the locked state.
     */
    protected function claim(Model $row): bool
    {
        $fresh = $row->newQueryWithoutScopes()->lockForUpdate()->find($row->getKey());
        if (! $fresh) {
            return false;
        }
        $unpaid = $fresh instanceof CbcEntry ? $fresh->status === 'pending' : $fresh->status !== 'paid';
        if (! $unpaid) {
            return false;
        }
        $row->setRawAttributes($fresh->getAttributes(), true);

        return true;
    }

    /** IC / GAP → member's cash wallet, net of TDS + service charge. */
    protected function approveLedger(CommissionLedger $row, string $paidOn): bool
    {
        if ($row->status === 'paid') {
            return false;
        }

        $ok = DB::transaction(function () use ($row, $paidOn) {
            if (! $this->claim($row)) {
                return false;
            }
            // amount is INR base; pay in the earner's currency at the rate frozen on the row.
            $gross = round((float) $row->amount / (float) ($row->fx_rate ?: 1), 2);
            // GAP: the slab is judged on the member's month total (legacy tbl_gap_log nettotal),
            // not on the single level row.
            $basis = $row->type === 'GAP'
                ? (float) CommissionLedger::where('member_id', $row->member_id)->where('type', 'GAP')
                    ->whereBetween('earned_on', [$row->earned_on->copy()->startOfMonth()->toDateString(), $row->earned_on->copy()->endOfMonth()->toDateString()])
                    ->sum('amount')
                : null;
            ['tds' => $tds, 'service' => $svc, 'net' => $net] = $this->deduct($gross, $row->type, $basis);
            // earning_total tracks gross earned; cash_balance gets the net payout.
            $this->creditWallet($row->member_id, cash: $net, earning: $gross, currency: $row->currency_code ?: 'INR');
            $row->update([
                'status' => 'paid', 'paid_on' => $paidOn,
                'tds' => $tds, 'service_charge' => $svc, 'net_amount' => $net,
            ]);

            return true;
        });

        return (bool) $ok;
    }

    /**
     * CBC (board 2026-11-01): sales-profit share → CASH wallet; TDS + service withheld on the
     * whole coupon; the remainder is retained by HQ on the row (coupon_amount). See cbcSplit().
     */
    protected function approveCbc(CbcEntry $row, string $paidOn): bool
    {
        if ($row->status !== 'pending') {
            return false;
        }

        $ok = DB::transaction(function () use ($row, $paidOn) {
            if (! $this->claim($row)) {
                return false;
            }
            // worth is INR base; pay in the currency + rate FROZEN at issue on the row
            // (rows from before the stamping default to INR/1.0 — unchanged behaviour).
            $fx = (float) ($row->fx_rate ?: 1);
            $ccy = $row->currency_code ?: 'INR';
            $worth = round((float) $row->worth / $fx, 2);
            // legacy bank report: a dealership plan's coupon is paid 100 % cash, TDS only
            $s = self::cbcSplit($worth, (bool) ($row->bond?->plan?->cbc_dealer_rule ?? false));
            // cash share only; the remainder stays on the row (coupon_amount) as an HQ figure
            $this->creditWallet($row->member_id, cash: $s['cash'], earning: $worth, currency: $ccy);
            $row->update([
                'status' => 'paid', 'paid_on' => $paidOn,
                'cash_amount' => $s['cash'], 'tds' => $s['tds'], 'service_charge' => $s['service'], 'coupon_amount' => $s['coupon'],
            ]);

            return true;
        });

        return (bool) $ok;
    }

    /**
     * How a CBC coupon of $worth is split: ['cash', 'tds', 'service', 'coupon'] — percentages
     * of the WHOLE worth from Commission Setup (CBC: cash_pct / tds / service), defaults 50 / 5 / 5;
     * the 40 % remainder is the HQ-only coupon wallet figure (legacy: the E-PIN/G12-savings share).
     *
     * $dealer = the legacy bank report's dealership exception (plans.cbc_dealer_rule — Taluka,
     * District, Zonal, Regional; user 2026-11-04): 100 % cash, 10 % TDS, no service charge,
     * nothing to the coupon wallet (CBC: dealer_cash_pct / dealer_tds / dealer_service).
     *
     * @return array{cash: float, tds: float, service: float, coupon: float, cash_pct: float, tds_pct: float, service_pct: float}
     */
    public static function cbcSplit(float $worth, bool $dealer = false): array
    {
        $cfg = self::chargeConfig('CBC');
        if ($dealer) {
            $cashPct = isset($cfg['dealer_cash_pct']) ? (float) $cfg['dealer_cash_pct'] : self::CBC_DEALER_CASH_PCT;
            $tdsPct = isset($cfg['dealer_tds']) ? (float) $cfg['dealer_tds'] : self::CBC_DEALER_TDS_PCT;
            $svcPct = isset($cfg['dealer_service']) ? (float) $cfg['dealer_service'] : self::CBC_DEALER_SERVICE_PCT;
        } else {
            $cashPct = isset($cfg['cash_pct']) ? (float) $cfg['cash_pct'] : self::CBC_CASH_PCT;
            $tdsPct = isset($cfg['tds']) ? (float) $cfg['tds'] : self::TDS_PCT;
            $svcPct = isset($cfg['service']) ? (float) $cfg['service'] : self::SERVICE_PCT;
        }
        $cash = round($worth * $cashPct / 100, 2);
        $tds = round($worth * $tdsPct / 100, 2);
        $svc = round($worth * $svcPct / 100, 2);
        $coupon = round(max(0, $worth - $cash - $tds - $svc), 2);

        return ['cash' => $cash, 'tds' => $tds, 'service' => $svc, 'coupon' => $coupon, 'cash_pct' => $cashPct, 'tds_pct' => $tdsPct, 'service_pct' => $svcPct];
    }

    /**
     * The split of a whole SET of coupons (a payout run, an approval window): dealership-plan
     * coupons and member-plan coupons are summed separately and split by their own rule.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CbcEntry>  $coupons
     * @return array{cash: float, tds: float, service: float, coupon: float, worth: float, dealer_worth: float, member_worth: float}
     */
    public static function cbcSplitTotals($coupons): array
    {
        // the caller's query carries unqualified where/order clauses — keep it as an id sub-select
        $ids = (clone $coupons)->getQuery()->reorder()->select('cbc_entries.id');
        $sums = \Illuminate\Support\Facades\DB::table('cbc_entries')
            ->leftJoin('bonds', 'bonds.id', '=', 'cbc_entries.bond_id')
            ->leftJoin('plans', 'plans.id', '=', 'bonds.plan_id')
            ->whereIn('cbc_entries.id', $ids)
            ->selectRaw('COALESCE(plans.cbc_dealer_rule, 0) AS dealer, SUM(cbc_entries.worth) AS worth')
            ->groupBy('dealer')
            ->get();
        $dealerWorth = 0.0;
        $memberWorth = 0.0;
        foreach ($sums as $s) {
            if ((int) $s->dealer === 1) {
                $dealerWorth += (float) $s->worth;
            } else {
                $memberWorth += (float) $s->worth;
            }
        }
        $d = self::cbcSplit($dealerWorth, true);
        $m = self::cbcSplit($memberWorth, false);

        return [
            'cash' => round($d['cash'] + $m['cash'], 2), 'tds' => round($d['tds'] + $m['tds'], 2),
            'service' => round($d['service'] + $m['service'], 2), 'coupon' => round($d['coupon'] + $m['coupon'], 2),
            'worth' => round($dealerWorth + $memberWorth, 2), 'dealer_worth' => round($dealerWorth, 2), 'member_worth' => round($memberWorth, 2),
        ];
    }

    /** Distributor margins → the branch's distributor member wallet (cash). */
    protected function approveReseller(ResellerCommission $row, string $paidOn): bool
    {
        if ($row->status === 'paid') {
            return false;
        }

        $ok = DB::transaction(function () use ($row, $paidOn) {
            if (! $this->claim($row)) {
                return false;
            }
            // com_value is INR base; pay in the earner's currency at the frozen rate.
            $gross = round((float) $row->com_value / (float) ($row->fx_rate ?: 1), 2);
            ['tds' => $tds, 'service' => $svc, 'net' => $net] = $this->deduct($gross, self::resellerTypeKey($row));
            $memberId = $this->distributorMemberId($row);
            if ($memberId) {
                $this->creditWallet($memberId, cash: $net, earning: $gross, currency: $row->currency_code ?: 'INR');
            }
            // No mapped distributor member → the branch balance already holds it; just mark paid.
            $row->update([
                'status' => 'paid', 'paid_on' => $paidOn,
                'tds' => $tds, 'service_charge' => $svc, 'net_amount' => $net,
            ]);

            return true;
        });

        return (bool) $ok;
    }

    /**
     * TDS + service charge withheld from a gross cash commission; returns the breakdown.
     * Rates come from Commission Setup (settings group 'commission', per stream key,
     * value = {"tds": %, "service": %}); the class constants are the fallback when a
     * stream has never been configured. CBC never reaches here (coupon — exempt).
     */
    protected function deduct(float $gross, ?string $type = null, ?float $basis = null): array
    {
        [$tdsPct, $svcPct] = self::chargesFor($type, $basis ?? $gross);

        $tds = round($gross * $tdsPct / 100, 2);
        $svc = round($gross * $svcPct / 100, 2);

        return ['tds' => $tds, 'service' => $svc, 'net' => round($gross - $tds - $svc, 2)];
    }

    /**
     * [tds %, service %] for a stream — configured value or the 5/5 default.
     *
     * Slab (legacy admin/reports paygap, docs/CUTOFF-PLAN.md): when the stream has `slab_from`
     * configured and the BASIS (the member's month total for GAP, the row's gross otherwise)
     * reaches it, the slab rates apply instead — legacy: ≥ ₹14,050 → 20 % in all, else 10 %.
     */
    public static function chargesFor(?string $type, ?float $basis = null): array
    {
        $row = self::chargeConfig($type);
        $tds = isset($row['tds']) ? (float) $row['tds'] : self::TDS_PCT;
        $svc = isset($row['service']) ? (float) $row['service'] : self::SERVICE_PCT;
        $slabFrom = (float) ($row['slab_from'] ?? 0);
        if ($slabFrom > 0 && $basis !== null && $basis >= $slabFrom) {
            $tds = isset($row['slab_tds']) ? (float) $row['slab_tds'] : $tds;
            $svc = isset($row['slab_service']) ? (float) $row['slab_service'] : $svc;
        }

        return [$tds, $svc];
    }

    /** @var array<string, array>|null  Commission Setup rows, loaded once per request */
    protected static ?array $chargeCache = null;

    /** Raw Commission Setup row of a stream: {tds, service, slab_from, slab_tds, slab_service}. */
    public static function chargeConfig(?string $type): array
    {
        if (self::$chargeCache === null) {
            self::$chargeCache = \App\Models\Setting::where('group', 'commission')
                ->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))
                ->all();
        }
        $row = $type !== null ? (self::$chargeCache[$type] ?? null) : null;

        return is_array($row) ? $row : [];
    }

    /** Forget the cached Commission Setup rows (after the page saves / in tests). */
    public static function forgetCharges(): void
    {
        self::$chargeCache = null;
    }

    /** Reverse of RESELLER_COM: a margin row's com_type_id → its stream key. */
    public static function resellerTypeKey(ResellerCommission $row): ?string
    {
        return array_search((int) $row->com_type_id, self::RESELLER_COM, true) ?: null;
    }

    /** Resolve a reseller margin's payee: branch → distributor login → mapped member. */
    protected function distributorMemberId(ResellerCommission $row): ?int
    {
        $branch = $row->branch;

        return $branch?->distributorUser?->memberAccount?->id;
    }

    /** Post to the single member wallet (separate balance buckets), creating it if absent. */
    protected function creditWallet(int $memberId, float $cash = 0, float $epin = 0, float $coupon = 0, float $earning = 0, string $currency = 'INR'): void
    {
        $wallet = MemberWallet::firstOrCreate(['member_id' => $memberId]);
        // Pin the wallet to the member's operating currency (all buckets are in it).
        if ($wallet->currency_code !== $currency) {
            $wallet->update(['currency_code' => $currency]);
        }
        if ($cash) {
            $wallet->increment('cash_balance', $cash);
        }
        if ($epin) {
            $wallet->increment('epin_balance', $epin);
        }
        if ($coupon) {
            $wallet->increment('coupon_balance', $coupon);
        }
        if ($earning) {
            $wallet->increment('earning_total', $earning);
        }
    }
}
