<?php

namespace App\Services;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\MemberWallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pays the commission types found in the legacy system, on the unified ledger.
 * TDS + service-charge rates are configurable constants (move to settings later).
 */
class CommissionService
{
    public const TDS_PCT = 5.0;
    public const SERVICE_PCT = 5.0;
    public const MAX_LEVELS = 5;
    public const CBC_TO_EPIN_PCT = 40.0;

    /** Instant Commission at the moment of purchase — up to 5 uplines, plan ic_schedule. */
    public function issueInstantCommission(Bond $bond): int
    {
        $schedule = $bond->plan->ic_schedule ?? [];
        if (empty($schedule)) {
            return 0;
        }

        $issued = 0;
        $credited = [];
        DB::transaction(function () use ($bond, $schedule, &$issued, &$credited) {
            $uplines = $this->uplineChain($bond->member, self::MAX_LEVELS);
            foreach ($uplines as $i => $upline) {
                $pct = (float) ($schedule[$i] ?? 0);
                if ($pct <= 0) {
                    continue;
                }

                // EP — Earning Potential gate (legacy Trade::checkmemberactive, restored
                // 2026-08-11): an upline earns IC only while ACTIVE with ≥1 active bond,
                // and never more per day than their invested bond value. The scheduled
                // amount is capped to the remaining headroom.
                $ep = $this->earningPotential($upline, (string) $bond->bond_date);
                if ($ep <= 0) {
                    continue;
                }

                $amount = min(round((float) $bond->value * $pct / 100, 2), round($ep, 2));
                $this->credit($upline, $amount, [
                    'type' => 'IC',
                    'from_member_id' => $bond->member_id,
                    'bond_id' => $bond->id,
                    'invoice_no' => $bond->invoice_no,
                    'level' => $i + 1,
                    'amount' => $amount,
                    'status' => 'pending',
                    'pay_via' => 'digi_transfer',
                    'earned_on' => $bond->bond_date,
                ]);
                $credited[] = ['upline' => $upline, 'amount' => $amount, 'level' => $i + 1];
                $issued++;
            }
        });

        // Downline-billing acknowledgement (board 2026-08-11) — after commit, never inside.
        if (! app()->runningUnitTests()) {
            $buyer = $bond->member?->member_code ?? 'your downline';
            foreach ($credited as $c) {
                \App\Services\Push\Notifier::to($c['upline'], 'network',
                    'New billing in your downline',
                    "{$buyer} billed ₹" . number_format((float) $bond->value, 2)
                        . ' — you earned ₹' . number_format($c['amount'], 2) . " (Level {$c['level']}, pending approval).",
                    route: '/earnings',
                    data: ['bond_id' => (string) $bond->id, 'level' => (string) $c['level']],
                );
            }
        }

        return $issued;
    }

    /**
     * GAP / level commission batch. For each active bond with installments left,
     * walk 5 uplines and pay level_schedule[n] only if the upline's rank depth >= n.
     */
    public function runGap(?CarbonInterface $period = null): int
    {
        $period ??= Carbon::now();
        $issued = 0;

        Bond::with('plan', 'member')
            ->where('status', 'active')
            ->whereColumn('lvlcom_issued', '<', 'lvlcom_count')
            ->chunkById(200, function ($bonds) use (&$issued, $period) {
                foreach ($bonds as $bond) {
                    $schedule = $bond->plan->level_schedule ?? [];
                    if (empty($schedule)) {
                        continue;
                    }
                    // ONE installment per calendar month per bond (legacy Ascript ran
                    // monthly). Makes re-runs — or an over-eager scheduler — harmless.
                    $alreadyThisMonth = CommissionLedger::where('bond_id', $bond->id)
                        ->where('type', 'GAP')
                        ->whereYear('earned_on', $period->year)
                        ->whereMonth('earned_on', $period->month)
                        ->exists();
                    if ($alreadyThisMonth) {
                        continue;
                    }
                    DB::transaction(function () use ($bond, $schedule, $period, &$issued) {
                        $uplines = $this->uplineChain($bond->member, self::MAX_LEVELS);
                        foreach ($uplines as $i => $upline) {
                            $level = $i + 1;
                            $pct = (float) ($schedule[$i] ?? 0);
                            if ($pct <= 0) {
                                continue;
                            }
                            // qualification: upline rank depth must reach this level
                            $depth = (int) ($upline->rank->depth ?? 0);
                            if ($depth < $level) {
                                continue;
                            }
                            // EP gate + cap (board 2026-08-11) — same rule as IC
                            $amount = $this->capByEp(
                                $upline,
                                round((float) $bond->value * $pct / 100, 2),
                                $period->toDateString(),
                            );
                            if ($amount <= 0) {
                                continue;
                            }
                            $this->credit($upline, $amount, [
                                'type' => 'GAP',
                                'from_member_id' => $bond->member_id,
                                'bond_id' => $bond->id,
                                'level' => $level,
                                'amount' => $amount,
                                'status' => 'pending',
                                'pay_via' => 'digi_transfer',
                                'earned_on' => $period->toDateString(),
                            ]);
                            $issued++;
                        }
                        $bond->increment('lvlcom_issued');
                    });
                }
            });

        return $issued;
    }

    /**
     * Issue one CBC installment per eligible active bond as a PENDING cbc_entries row.
     * No wallet credit here — the admin approves it on the Commission Approval page,
     * which posts the 40% EPIN / 60% coupon split (CommissionApprovalService).
     */
    public function issueCbc(?CarbonInterface $period = null): int
    {
        $period ??= Carbon::now();
        $issued = 0;

        Bond::with('member')
            ->where('status', 'active')
            ->where('cbc_value', '>', 0)
            ->whereColumn('cbc_issued', '<', 'cbc_count')
            ->chunkById(200, function ($bonds) use (&$issued, $period) {
                foreach ($bonds as $bond) {
                    // ONE CBC per calendar month per bond — same idempotency rule as GAP.
                    $alreadyThisMonth = \App\Models\CbcEntry::where('bond_id', $bond->id)
                        ->whereYear('cbc_date', $period->year)
                        ->whereMonth('cbc_date', $period->month)
                        ->exists();
                    if ($alreadyThisMonth) {
                        continue;
                    }
                    DB::transaction(function () use ($bond, $period, &$issued) {
                        // worth is INR base; freeze the earner's currency + rate at issue
                        // (approval converts at THIS rate, never a later one).
                        $branch = $bond->member?->branch_id
                            ? \App\Models\Branch::find($bond->member->branch_id) : null;
                        \App\Models\CbcEntry::create([
                            'bond_id' => $bond->id,
                            'member_id' => $bond->member_id,
                            'cbc_date' => $period->toDateString(),
                            'code' => strtoupper(\Illuminate\Support\Str::random(12)),
                            'worth' => round((float) $bond->cbc_value, 2),
                            'currency_code' => $branch?->currency_code ?: 'INR',
                            'fx_rate' => $branch?->fxRateToBase() ?? 1.0,
                            'status' => 'pending',
                        ]);
                        $bond->increment('cbc_issued');
                        $issued++;
                    });
                }
            });

        return $issued;
    }

    /**
     * Roll pending commissions for a period into per-member payout statements,
     * applying TDS + service charge, and mark the ledger rows approved.
     */
    public function generatePayoutStatements(string $period): int
    {
        // period = YYYY-MM
        [$year, $month] = explode('-', $period);
        $made = 0;

        $rows = CommissionLedger::query()
            ->where('status', 'pending')
            ->whereYear('earned_on', $year)
            ->whereMonth('earned_on', $month)
            ->select('member_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('member_id')
            ->get();

        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $period, $year, $month, &$made) {
                $net = (float) $row->total;
                $tds = round($net * self::TDS_PCT / 100, 2);
                $svc = round($net * self::SERVICE_PCT / 100, 2);
                $grand = round($net - $tds - $svc, 2);

                DB::table('payout_statements')->updateOrInsert(
                    ['member_id' => $row->member_id, 'period' => $period],
                    [
                        'item_count' => $row->cnt,
                        'net_total' => $net,
                        'tds' => $tds,
                        'service_charge' => $svc,
                        'grand_total' => $grand,
                        'status' => 'pending',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                // Scope to the statement's own period — flipping ALL pending rows would
                // bypass the manual commission-approval gate for later earnings.
                CommissionLedger::where('member_id', $row->member_id)
                    ->where('status', 'pending')
                    ->whereYear('earned_on', $year)
                    ->whereMonth('earned_on', $month)
                    ->update(['status' => 'approved']);

                $made++;
            });
        }

        return $made;
    }

    /** @return array<int,Member> ordered upline-1 first */
    protected function uplineChain(Member $member, int $depth): array
    {
        $chain = [];
        $current = $member->upline;
        while ($current && count($chain) < $depth) {
            $current->loadMissing('rank');
            $chain[] = $current;
            $current = $current->upline;
        }
        return $chain;
    }

    protected function wallet(Member $member): MemberWallet
    {
        return MemberWallet::firstOrCreate(['member_id' => $member->id]);
    }

    /**
     * Record an earning as PENDING only. The wallet is credited later, when an admin
     * approves it on the Commission Approval page (CommissionApprovalService) — funds
     * never reach a wallet before approval.
     *
     * The amount is INR base; the earner's home currency + INR rate are frozen HERE,
     * at earn time — approval converts at exactly this rate (same policy as the
     * billing-path IC in SalesService). India = INR/1.0, byte-identical behaviour.
     */
    /**
     * EP — Earning Potential (legacy Trade::checkmemberactive): the member's remaining
     * same-day earning headroom in rupees.
     *
     *   EP = Σ(active bond values) − (today's IC + GAP + reseller margins already booked)
     *
     * Returns 0 when the member is inactive, holds no active bond, or has exhausted
     * the headroom — a 0 means "earns nothing further today".
     */
    public function earningPotential(Member $member, ?string $onDate = null): float
    {
        if ($member->status !== 'active') {
            return 0.0;
        }

        $bonds = Bond::where('member_id', $member->id)->where('status', 'active');
        $activeBonds = (int) $bonds->count();
        if ($activeBonds < 1) {
            return 0.0;
        }
        $boundedValue = (float) $bonds->sum('value');

        $date = $onDate ?: Carbon::now()->toDateString();
        $earned = (float) CommissionLedger::where('member_id', $member->id)
            ->whereDate('earned_on', '>=', $date)->sum('amount')
            + (float) \App\Models\ResellerCommission::where(fn ($w) => $w
                ->where('reference_member_id', $member->id)
                ->orWhere('mapped_uid', $member->member_code))
                ->whereDate('bill_date', '>=', $date)->sum('com_value');

        return $boundedValue > $earned ? round($boundedValue - $earned, 2) : 0.0;
    }

    /**
     * EP applied to a payable amount (board 2026-08-11: EVERY cash stream is EP-
     * filtered; CBC coupons are exempt). No mapped beneficiary (e.g. a branch with
     * no dealer login) → uncapped, the branch balance simply holds the margin.
     */
    public function capByEp(?Member $beneficiary, float $amount, ?string $onDate = null): float
    {
        if (! $beneficiary || $amount <= 0) {
            return $amount;
        }
        $ep = $this->earningPotential($beneficiary, $onDate);

        return $ep <= 0 ? 0.0 : min($amount, round($ep, 2));
    }

    protected function credit(Member $member, float $amount, array $ledger): void
    {
        $branch = $member->branch_id ? \App\Models\Branch::find($member->branch_id) : null;
        CommissionLedger::create($ledger + [
            'member_id' => $member->id,
            'currency_code' => $branch?->currency_code ?: 'INR',
            'fx_rate' => $branch?->fxRateToBase() ?? 1.0,
        ]);
    }
}
