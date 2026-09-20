<?php

namespace App\Support\BusinessModel;

use App\Models\Plan;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The legacy Lord DB (`lordicl`, CodeIgniter) read over the read-only `legacy`
 * connection — the book the board actually knows (board 2026-09-16: "take the data
 * from lordicl db → tbl_bond").
 *
 *   tbl_bond        one row per scheme sale = the contract (epinvalue = the full amount
 *                   invested = Started Amount; bvalue = the gold-stock share of it, and the
 *                   monthly installment on RD plans; bondstatus 1 active,
 *                   0 = closed by the monthly Ascript once the term has elapsed —
 *                   NOT a sign that every due was paid or a settlement was issued)
 *   tbl_rdentry     RD installments (renewals) per bond
 *   tbl_digi_queue  gold QR allocations; route INVOICE rows reference the bond's
 *                   invoice number (refferance) and the member's user id
 *   tbl_planjewel   legacy plan master — matched to the new plan master by English name
 *
 * The legacy tables carry no useful indexes and mixed collations, so every join
 * happens in PHP on small, filtered row sets. Nothing here writes.
 */
class LegacyLordSource implements ReportSource
{
    /** legacy planid keyed by lower-cased English plan name. */
    protected ?Collection $planIds = null;

    /** All route=INVOICE QR rows keyed by "refferance|userid" (≈7k rows, loaded once per run). */
    protected ?Collection $invoiceQrs = null;

    public static function available(): bool
    {
        try {
            DB::connection('legacy')->getPdo();

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
        return __('Lord DB (lordicl · tbl_bond)');
    }

    public function firstDate(): ?Carbon
    {
        $first = $this->db()->table('tbl_bond')->where('planid', '<>', '')->where('bdate', '>', '2000-01-01')->min('bdate');

        return $first ? Carbon::parse($first) : null;
    }

    /** Legacy planid for a new-system plan, by English name (codes were reshuffled between the systems). */
    public function legacyPlanId(Plan $plan): ?string
    {
        $this->planIds ??= collect($this->db()->table('tbl_planjewel')->get(['planid', 'plannameen', 'planname']))
            ->mapWithKeys(fn ($p) => [self::norm($p->plannameen ?: $p->planname) => (string) $p->planid]);

        $name = is_array($plan->name) ? ($plan->name['en'] ?? reset($plan->name)) : $plan->name;

        return $this->planIds->get(self::norm((string) $name));
    }

    protected static function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    public function contracts(Plan $plan, Carbon $from, Carbon $to): Collection
    {
        $pid = $this->legacyPlanId($plan);
        if ($pid === null) {
            return collect();
        }
        $validity = (int) ($plan->validity_months ?: 12);

        $rows = $this->db()->table('tbl_bond')
            ->where('planid', $pid)
            ->whereBetween('bdate', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bdate')->orderBy('bondid')
            ->get(['bondid', 'bdate', 'memid', 'memname', 'memuserid', 'bvalue', 'epinvalue', 'invoiceno', 'branchname', 'bondstatus']);
        $gst = $rows->isEmpty() ? collect() : $this->db()->table('tbl_member')
            ->whereIn('id', $rows->pluck('memid')->filter()->unique()->all())->where('gstno', '<>', '')
            ->pluck('gstno', 'id')->map(fn ($v) => strtoupper(trim((string) $v)));

        return collect($rows)
            ->map(fn ($b) => (object) [
                'id' => (int) $b->bondid,
                'contract_no' => 'Bond '.$b->bondid.' / Inv '.$b->invoiceno,
                'bond_id' => (int) $b->bondid,
                'member_id' => (string) $b->memid,
                'member' => trim($b->memuserid.' '.$b->memname) ?: '—',
                'member_name' => trim((string) $b->memname) ?: '—',
                'member_uid' => trim((string) $b->memuserid),
                'gst' => (string) ($gst->get((int) $b->memid) ?? ''),
                'branch' => trim((string) $b->branchname) ?: '—',
                'amount' => self::startedAmount($b, $plan->type === 'rd'),
                'monthly' => (float) $b->bvalue,
                'start_date' => Carbon::parse($b->bdate),
                'end_date' => Carbon::parse($b->bdate)->addMonthsNoOverflow($validity),
                'status' => (int) $b->bondstatus === 1 ? 'active' : 'closed',
                'settled_on' => null,                   // the Lord DB keeps no settlement date
                'is_rd' => $plan->type === 'rd',
                'invoice_no' => (string) $b->invoiceno,
                'user_id' => (string) $b->memuserid,
            ]);
    }

    /** Started Amount of a bond: the full invested value (epinvalue); RD plans keep bvalue for its paise. */
    public static function startedAmount(object $b, bool $isRd): float
    {
        $epin = (float) ($b->epinvalue ?? 0);

        return ($isRd || $epin <= 0) ? (float) $b->bvalue : $epin;
    }

    public function rdEntries(array $bondIds, Carbon $asOf): Collection
    {
        if (! $bondIds) {
            return collect();
        }

        return collect($this->db()->table('tbl_rdentry')
            ->whereIn('bondid', $bondIds)->where('trdate', '<=', $asOf->toDateString())
            ->get(['bondid', 'trdate', 'bvalue']))
            ->map(fn ($r) => (object) ['bond_id' => (int) $r->bondid, 'paid_on' => Carbon::parse($r->trdate), 'value' => (float) $r->bvalue])
            ->groupBy('bond_id');
    }

    public function qrs(array $bondIds, Carbon $asOf): Collection
    {
        if (! $bondIds) {
            return collect();
        }
        $this->invoiceQrs ??= collect($this->db()->table('tbl_digi_queue')
            ->where('route', 'INVOICE')
            ->get(['refferance', 'userid', 'dot', 'qrmode', 'gm_worth', 'cash_worth', 'deliverystatus']))
            ->groupBy(fn ($q) => trim((string) $q->refferance).'|'.strtoupper(trim((string) $q->userid)));

        $bonds = $this->db()->table('tbl_bond')->whereIn('bondid', $bondIds)->get(['bondid', 'invoiceno', 'memuserid']);
        $out = [];
        foreach ($bonds as $b) {
            $rows = $this->invoiceQrs->get(trim((string) $b->invoiceno).'|'.strtoupper(trim((string) $b->memuserid)), collect());
            foreach ($rows as $q) {
                $dot = Carbon::parse($q->dot);
                if ($dot->gt($asOf)) {
                    continue;
                }
                $out[(int) $b->bondid][] = (object) [
                    'bond_id' => (int) $b->bondid,
                    'created_at' => $dot,
                    'gram_worth' => (float) $q->gm_worth,
                    'cash_worth' => (float) $q->cash_worth,
                    'status' => strtoupper((string) $q->deliverystatus) === 'REDEEMED' ? 'redeemed' : 'pending',
                    'mode' => strtoupper((string) $q->qrmode),
                ];
            }
        }

        return collect($out)->map(fn ($rows) => collect($rows));
    }

    /** The Lord DB has no cash-settlement register. */
    public function settlements(array $contractIds, Carbon $asOf): Collection
    {
        return collect();
    }

    public function overview(Carbon $from, Carbon $to): array
    {
        $db = $this->db();
        $window = fn ($q, string $col) => $q->whereBetween($col, [$from->toDateString(), $to->toDateString()]);

        $bonds = $window($db->table('tbl_bond')->where('planid', '<>', ''), 'bdate')
            ->selectRaw('bondstatus, COUNT(*) c, COALESCE(SUM(CASE WHEN epinvalue > 0 THEN epinvalue ELSE CAST(bvalue AS DECIMAL(18,2)) END),0) t')
            ->groupBy('bondstatus')->get()->keyBy(fn ($r) => (int) $r->bondstatus);
        $active = $bonds->get(1);
        $closed = $bonds->get(0);

        $rd = $window($db->table('tbl_rdentry'), 'trdate')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(CAST(bvalue AS DECIMAL(18,2))),0) t')->first();
        $qrs = $window($db->table('tbl_digi_queue')->where('route', 'INVOICE')->where('qrmode', 'GOLD'), 'dot')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(CAST(gm_worth AS DECIMAL(18,4))),0) g, COALESCE(SUM(CAST(cash_worth AS DECIMAL(18,2))),0) t')->first();

        return [
            'hq' => 1,
            'regional' => $this->dealersByName('Regional Dealership'),
            'zonal' => $this->dealersByName('Zonal Dealership'),
            'district' => $this->dealersByName('District Dealership'),
            'taluk' => $this->dealersByName('Taluka Dealership'),
            'retail' => $this->dealersByName('G24 Wholesaler Plan', 'G5 Retailer Plan', 'Sub Dealer', 'Area Distributor'),
            'schemes' => (int) $db->table('tbl_planjewel')->where('status', 'ACTIVE')->where('planvisible', '1')->count(),
            'members' => (int) $db->table('tbl_member')->count(),
            'contracts' => (int) (($active->c ?? 0) + ($closed->c ?? 0)),
            'contracts_amount' => (float) (($active->t ?? 0) + ($closed->t ?? 0)),
            'active' => (int) ($active->c ?? 0), 'active_amount' => (float) ($active->t ?? 0),
            'closed' => (int) ($closed->c ?? 0), 'closed_amount' => (float) ($closed->t ?? 0),
            'rd' => (int) $rd->c, 'rd_amount' => (float) $rd->t,
            'qrs' => (int) $qrs->c, 'qrs_grams' => (float) $qrs->g, 'qrs_amount' => (float) $qrs->t,
            'settlements' => 0, 'settlements_amount' => 0.0,
            'settlements_note' => __('The Lord DB keeps no settlement register: settlements were handed over by hand. Every settlement whose due date has passed is shown as handed over at the scheme-rule value; future ones are projected.'),
            'qr_note' => ($firstQr = $db->table('tbl_digi_queue')->where('route', 'INVOICE')->where('dot', '>', '2000-01-01')->min('dot'))
                ? __('The gold QR register in the Lord DB starts :d — installments and bonds billed before that show no QR.', ['d' => Carbon::parse($firstQr)->format('d M Y')])
                : null,
        ];
    }

    /** Distinct members holding an ACTIVE bond on the named legacy plans. */
    protected function dealersByName(string ...$names): int
    {
        $ids = collect($names)->map(function ($n) {
            $this->planIds ??= collect($this->db()->table('tbl_planjewel')->get(['planid', 'plannameen', 'planname']))
                ->mapWithKeys(fn ($p) => [self::norm($p->plannameen ?: $p->planname) => (string) $p->planid]);

            return $this->planIds->get(self::norm($n));
        })->filter()->values()->all();

        if (! $ids) {
            return 0;
        }

        return (int) $this->db()->table('tbl_bond')->whereIn('planid', $ids)->where('bondstatus', 1)->distinct()->count('memid');
    }

    public function dealersOnScheme(Plan $plan, ?string $level): int
    {
        $pid = $this->legacyPlanId($plan);

        return $pid === null ? 0
            : (int) $this->db()->table('tbl_bond')->where('planid', $pid)->where('bondstatus', 1)->distinct()->count('memid');
    }

    public function dealersLabel(): string
    {
        return __('Active dealers holding this scheme');
    }

    public function settlesOffline(): bool
    {
        return true;
    }

    /** ci_users.desiid → level (6 = Area Dealer is not a report level). */
    public const DESIGNATIONS = ['zonal' => '1', 'district' => '2', 'taluk' => '3', 'wholesaler' => '4', 'reseller' => '5'];

    /** Dealership plans whose bonds belong to a dealer login → gold-stock share of the bond value. */
    public const DEALER_PLAN_SHARE = ['201' => 0.50, '203' => 0.80, '204' => 0.80, '205' => 0.70, '208' => 0.80];

    public const DEALER_PLAN_IDS = ['201', '203', '204', '205', '208'];

    /** Per member user id: earliest bond date, full bond value, invoice numbers, count — over every dealership bond. */
    protected ?Collection $dealerBonds = null;

    public function dealerLogins(string $level): Collection
    {
        $desi = self::DESIGNATIONS[$level] ?? null;
        if ($desi === null) {
            return collect();
        }

        $this->dealerBonds ??= collect($this->db()->table('tbl_bond')
            ->whereIn('planid', self::DEALER_PLAN_IDS)->where('bdate', '>', '2000-01-01')
            ->get(['memuserid', 'bdate', 'bvalue', 'epinvalue', 'invoiceno', 'planid']))
            ->groupBy(fn ($b) => strtoupper(trim((string) $b->memuserid)))
            ->map(fn ($rows) => (object) [
                'first' => $rows->min('bdate'),
                'value' => (float) $rows->sum(fn ($b) => self::startedAmount($b, false)),
                'bonds' => $rows->map(fn ($b) => (object) ['invoice' => trim((string) $b->invoiceno), 'value' => self::startedAmount($b, false), 'share' => self::DEALER_PLAN_SHARE[(string) $b->planid] ?? 0.8])->all(),
                'count' => $rows->count(),
            ]);
        $this->invoiceQrs ??= collect($this->db()->table('tbl_digi_queue')
            ->where('route', 'INVOICE')
            ->get(['refferance', 'userid', 'dot', 'qrmode', 'gm_worth', 'cash_worth', 'deliverystatus']))
            ->groupBy(fn ($q) => trim((string) $q->refferance).'|'.strtoupper(trim((string) $q->userid)));

        $incharges = $this->db()->table('tbl_branches')->pluck('brincharge', 'brid')
            ->map(fn ($v) => trim((string) $v));
        $branchGst = $this->db()->table('tbl_branches')->pluck('gstno', 'brid')
            ->map(fn ($v) => strtoupper(trim((string) $v)));
        $memberGst = $this->db()->table('tbl_member')->where('gstno', '<>', '')->pluck('gstno', 'userid')
            ->mapWithKeys(fn ($v, $k) => [strtoupper(trim((string) $k)) => strtoupper(trim((string) $v))]);

        return collect($this->db()->table('ci_users')
            ->where('desiid', $desi)->where('is_admin', 0)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'username', 'firstname', 'mapid', 'brid', 'brname', 'created_at', 'invested', 'is_active']))
            ->map(function ($u) use ($incharges, $branchGst, $memberGst) {
                $uid = strtoupper(trim((string) $u->mapid));
                $bonds = $this->dealerBonds->get($uid);

                // Gold allocated per bond: the INVOICE-route QRs on its invoice, else (bonds billed
                // before the QR register) the plan's gold-stock share of the bond value.
                $allocation = null;
                if ($bonds) {
                    $allocation = 0.0;
                    foreach ($bonds->bonds as $b) {
                        $qrs = $this->invoiceQrs->get($b->invoice.'|'.$uid, collect());
                        $allocation += $qrs->isNotEmpty() ? (float) $qrs->sum(fn ($q) => (float) $q->cash_worth) : round($b->value * $b->share, 2);
                    }
                }

                return (object) [
                    'name' => trim((string) ($u->username ?: $u->firstname)) ?: '—',
                    'uid' => trim((string) $u->mapid),
                    'branch' => trim((string) $u->brname) ?: '—',
                    'incharge' => (string) ($incharges->get((int) $u->brid) ?? ''),
                    'gst' => (string) ($memberGst->get($uid) ?: ($branchGst->get((int) $u->brid) ?? '')),
                    'start_date' => Carbon::parse($bonds?->first ?: $u->created_at),
                    'amount' => $bonds && $bonds->value > 0 ? $bonds->value : (float) $u->invested,
                    'allocation' => $allocation,
                    'bonds' => (int) ($bonds?->count ?? 0),
                    'active' => (int) $u->is_active === 1,
                ];
            });
    }

    public function coverageNote(Plan $plan): ?string
    {
        return $this->legacyPlanId($plan) === null
            ? __('Not offered in the Lord DB — no bonds to list.')
            : null;
    }
}
