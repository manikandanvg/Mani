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
        DB::transaction(function () use ($bond, $schedule, &$issued) {
            $uplines = $this->uplineChain($bond->member, self::MAX_LEVELS);
            foreach ($uplines as $i => $upline) {
                $pct = (float) ($schedule[$i] ?? 0);
                if ($pct <= 0) {
                    continue;
                }
                $amount = round((float) $bond->value * $pct / 100, 2);
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
                $issued++;
            }
        });

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
                            $amount = round((float) $bond->value * $pct / 100, 2);
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
                    DB::transaction(function () use ($bond, $period, &$issued) {
                        \App\Models\CbcEntry::create([
                            'bond_id' => $bond->id,
                            'member_id' => $bond->member_id,
                            'cbc_date' => $period->toDateString(),
                            'code' => strtoupper(\Illuminate\Support\Str::random(12)),
                            'worth' => round((float) $bond->cbc_value, 2),
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
            DB::transaction(function () use ($row, $period, &$made) {
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

                CommissionLedger::where('member_id', $row->member_id)
                    ->where('status', 'pending')
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
     */
    protected function credit(Member $member, float $amount, array $ledger): void
    {
        CommissionLedger::create($ledger + ['member_id' => $member->id]);
    }
}
