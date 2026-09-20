<?php

namespace App\Support\BusinessModel;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\ContractSettlement;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\RdEntry;
use App\Models\RedeemableQr;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The new system's own tables (member_contracts / bonds / rd_entries / redeemable_qrs / contract_settlements). */
class NextSystemSource implements ReportSource
{
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
        $first = Bond::min('bond_date');

        return $first ? Carbon::parse($first) : null;
    }

    public function contracts(Plan $plan, Carbon $from, Carbon $to): Collection
    {
        return MemberContract::with(['member', 'branch', 'bond'])
            ->where('plan_id', $plan->id)
            ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)
            ->orderBy('start_date')->orderBy('id')->get()
            ->map(fn (MemberContract $c) => (object) [
                'id' => $c->id,
                'contract_no' => $c->contract_no,
                'bond_id' => $c->bond_id,
                'member_id' => $c->member_id,
                'member' => trim(($c->member?->member_code ?? '').' '.($c->member?->name ?? '')) ?: '—',
                'member_name' => trim((string) ($c->member?->name ?? '')) ?: '—',
                'member_uid' => trim((string) ($c->member?->member_code ?? '')),
                'gst' => '',                                   // members carry no GST number in the new schema
                'branch' => $c->branch?->name ?? '—',
                'amount' => (float) $c->amount,
                'monthly' => (float) ($c->bond?->value ?? $c->amount),
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'status' => $c->status,
                'settled_on' => $c->settled_on ? Carbon::parse($c->settled_on) : null,
                'is_rd' => $plan->type === 'rd',
            ]);
    }

    public function rdEntries(array $bondIds, Carbon $asOf): Collection
    {
        return RdEntry::whereIn('bond_id', $bondIds)->whereDate('paid_on', '<=', $asOf)->get()
            ->map(fn ($r) => (object) ['bond_id' => $r->bond_id, 'paid_on' => $r->paid_on, 'value' => (float) $r->value])
            ->groupBy('bond_id');
    }

    public function qrs(array $bondIds, Carbon $asOf): Collection
    {
        return RedeemableQr::whereIn('bond_id', $bondIds)->where('created_at', '<=', $asOf)->get()
            ->map(fn ($q) => (object) [
                'bond_id' => $q->bond_id,
                'created_at' => $q->created_at,
                'gram_worth' => (float) $q->gram_worth,
                'cash_worth' => (float) $q->cash_worth,
                'status' => $q->status === 'redeemed' ? 'redeemed' : 'pending',
            ])
            ->groupBy('bond_id');
    }

    public function settlements(array $contractIds, Carbon $asOf): Collection
    {
        return ContractSettlement::whereIn('member_contract_id', $contractIds)->whereDate('paid_on', '<=', $asOf)->get()
            ->map(fn ($s) => (object) ['contract_id' => $s->member_contract_id, 'amount' => (float) $s->amount, 'paid_on' => $s->paid_on])
            ->groupBy('contract_id');
    }

    public function overview(Carbon $from, Carbon $to): array
    {
        $branches = Branch::where('is_active', true)->selectRaw('level, COUNT(*) c')->groupBy('level')->pluck('c', 'level');
        $count = fn (array $levels) => (int) collect($levels)->sum(fn ($l) => (int) ($branches[$l] ?? 0));

        $contracts = MemberContract::whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)->get();
        [$active, $closed] = $contracts->partition(fn ($c) => ! ContractStatus::isClosed($c->status, $c->settled_on ? Carbon::parse($c->settled_on) : null, $c->end_date, $to));

        $rd = RdEntry::whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(value),0) t')->first();
        $qrs = RedeemableQr::where('created_at', '>=', $from)->where('created_at', '<=', $to)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(gram_worth),0) g, COALESCE(SUM(cash_worth),0) t')->first();
        $paid = ContractSettlement::whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();

        return [
            'hq' => $count(['hq']),
            'regional' => $count(['regional']),
            'zonal' => $count(['zonal']),
            'district' => $count(['district']),
            'taluk' => $count(['taluk']),
            'retail' => $count(['wholesaler', 'reseller', 'sub_dealer', 'area_dealer']),
            'schemes' => Plan::where('is_active', true)->count(),
            'members' => Member::count(),
            'contracts' => $contracts->count(), 'contracts_amount' => (float) $contracts->sum('amount'),
            'active' => $active->count(), 'active_amount' => (float) $active->sum('amount'),
            'closed' => $closed->count(), 'closed_amount' => (float) $closed->sum('amount'),
            'rd' => (int) $rd->c, 'rd_amount' => (float) $rd->t,
            'qrs' => (int) $qrs->c, 'qrs_grams' => (float) $qrs->g, 'qrs_amount' => (float) $qrs->t,
            'settlements' => (int) $paid->c, 'settlements_amount' => (float) $paid->t,
            'settlements_note' => null,
            'qr_note' => null,
        ];
    }

    public function dealersOnScheme(Plan $plan, ?string $level): int
    {
        return $level ? Branch::where('is_active', true)->where('level', $level)->count() : 0;
    }

    public function dealersLabel(): string
    {
        return __('Branches at this level');
    }

    public function settlesOffline(): bool
    {
        return false;
    }

    /** Distributor logins whose branch sits at the level (users.invested = Started Amount). */
    public function dealerLogins(string $level): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'distributor'))
            ->whereHas('branch', fn ($q) => $q->where('level', $level))
            ->with(['branch', 'memberAccount'])
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(function ($u) {
                // Every dealership contract of the member (plans on the ladder), full value + gold allocated.
                $contracts = $u->memberAccount
                    ? MemberContract::with('plan')->where('member_id', $u->memberAccount->id)
                        ->whereHas('plan', fn ($q) => $q->whereNotNull('hid'))->get()
                    : collect();
                // Gold allocated per contract: its QRs, else the plan's allocation share of the amount.
                $qrByBond = $contracts->isNotEmpty()
                    ? RedeemableQr::whereIn('bond_id', $contracts->pluck('bond_id'))->selectRaw('bond_id, SUM(cash_worth) t')->groupBy('bond_id')->pluck('t', 'bond_id')
                    : collect();
                $allocation = (float) $contracts->sum(fn ($c) => isset($qrByBond[$c->bond_id])
                    ? (float) $qrByBond[$c->bond_id]
                    : round((float) $c->amount * (float) ($c->plan?->allocation_cont ?? 80) / 100, 2));

                return (object) [
                    'name' => trim((string) ($u->memberAccount?->name ?: $u->name)) ?: '—',
                    'uid' => (string) ($u->member_code ?: ''),
                    'branch' => $u->branch?->name ?? '—',
                    'incharge' => trim((string) ($u->branch?->incharge ?? '')),
                    'gst' => strtoupper(trim((string) ($u->branch?->gst_no ?? ''))),
                    'start_date' => $contracts->isNotEmpty()
                        ? $contracts->min('start_date')
                        : ($u->memberAccount?->joined_on ? Carbon::parse($u->memberAccount->joined_on) : Carbon::parse($u->created_at)),
                    'amount' => $contracts->isNotEmpty() ? (float) $contracts->sum('amount') : (float) $u->invested,
                    'allocation' => $allocation > 0 ? $allocation : null,
                    'bonds' => $contracts->count(),
                    'active' => $u->status === 'active',
                ];
            });
    }

    public function coverageNote(Plan $plan): ?string
    {
        return null;
    }
}
