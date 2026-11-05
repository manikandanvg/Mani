<?php

namespace App\Services\Network;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\SalesInvoice;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a distributor's Senior Distributor (placement) after billing (user 2026-11-05:
 * from 2026-11-02 the Sales screen no longer copied the Referred ID into the placement on
 * blur, as legacy did, and distributors keyed wrong uplines).
 *
 * Placement lives in ONE column (members.upline_id) — the genealogy, BV/GBV and ranks are
 * derived from it — but billing froze two things from it that must follow the move:
 *   1. bonds.upline_member_id — the level-1 upline the monthly cut-off pays (the member's own bonds);
 *   2. the Sales Incentive (IC) rows — 10 levels up the chain, for the member's own bills AND for
 *      every bill of their downline whose chain passes through them (levels above the moved node).
 *
 * IC per level: a row already on the right upline stays; a PENDING row on a wrong upline is
 * removed and re-issued to the right one (same base, same date, same EP cap rule as billing);
 * an APPROVED / PAID row on a wrong upline is never touched — it is reported, and re-issued to
 * the right upline only with $reissuePaid (HQ then recovers the wrong credit itself).
 * Idempotent: a second run finds every level already on the right upline.
 */
class PlacementCorrectionService
{
    public const IC_LEVELS = 10;

    /** Members joined on/after $since whose placement differs from their referrer. */
    public function mismatches(string $since): \Illuminate\Support\Collection
    {
        return Member::query()
            ->with(['upline:id,member_code,name', 'referrer:id,member_code,name', 'branch:id,name'])
            ->where('joined_on', '>=', $since)
            ->whereNotNull('referrer_id')
            ->whereColumn('referrer_id', '!=', 'upline_id')
            ->orderBy('joined_on')->orderBy('id')
            ->get();
    }

    /**
     * Move $member under $newUpline. Returns a report array; writes only when $apply.
     *
     * @return array{member:string, from:?string, to:string, bonds:int, ic_moved:int, ic_moved_amount:float,
     *               ic_issued:int, ic_issued_amount:float, paid_wrong:array<int,array>, error:?string}
     */
    public function move(Member $member, Member $newUpline, bool $apply, bool $reissuePaid = false): array
    {
        $report = [
            'member' => $member->member_code, 'from' => $member->upline?->member_code, 'to' => $newUpline->member_code,
            'bonds' => 0, 'ic_removed' => 0, 'ic_removed_amount' => 0.0, 'ic_issued' => 0, 'ic_issued_amount' => 0.0,
            'paid_wrong' => [], 'error' => null,
        ];

        // full rows: callers may pass relations loaded with a few columns only
        $member = Member::findOrFail($member->id);
        $newUpline = Member::findOrFail($newUpline->id);
        if ($newUpline->id === $member->id) {
            return ['error' => 'a distributor cannot be their own senior'] + $report;
        }
        // loop guard on the WHOLE chain above the new senior, not just 10 levels
        $seen = [];
        for ($id = $newUpline->id; $id && ! isset($seen[$id]); $id = Member::whereKey($id)->value('upline_id')) {
            if ((int) $id === $member->id) {
                return ['error' => "{$newUpline->member_code} is in {$member->member_code}'s own downline — that would make a loop"] + $report;
            }
            $seen[$id] = true;
        }
        $subtree = $this->subtree($member->id, self::IC_LEVELS);   // [member_id => depth below $member]

        $run = function () use ($member, $newUpline, $subtree, $apply, $reissuePaid, &$report) {
            $oldUplineId = $member->upline_id;
            // the member's own bonds frozen on the old senior (null = "current upline", follows by itself)
            $frozen = fn () => Bond::where('member_id', $member->id)
                ->where('upline_member_id', (int) $oldUplineId === (int) $newUpline->id ? 0 : (int) $oldUplineId);
            $member->upline_id = $newUpline->id;
            if ($apply) {
                Member::whereKey($member->id)->update(['upline_id' => $newUpline->id]);
                $report['bonds'] = $frozen()->update(['upline_member_id' => $newUpline->id]);
            } else {
                $report['bonds'] = $frozen()->count();
            }

            // chain above the moved member, as it will be after the move
            $above = [];
            for ($u = $newUpline; $u && count($above) < self::IC_LEVELS; $u = $u->upline) {
                $above[] = $u;
            }

            $bonds = Bond::with('plan', 'member')->whereIn('member_id', array_keys($subtree))
                ->where('status', '!=', 'cancelled')->get();
            foreach ($bonds as $bond) {
                $depth = $subtree[$bond->member_id];          // 0 = the moved member's own bond
                $this->fixBondIc($bond, $depth, $above, $apply, $reissuePaid, $report);
            }
        };

        $apply ? DB::transaction($run) : $run();

        if ($apply) {
            \App\Services\Activity\ActivityLogger::log('network', 'placement_corrected',
                "Senior Distributor of {$member->member_code} changed {$report['from']} → {$report['to']}", [
                    'subject' => $member, 'details' => array_diff_key($report, ['paid_wrong' => 1]) + ['paid_wrong_rows' => count($report['paid_wrong'])],
                ]);
        }

        return $report;
    }

    /** Levels above the moved node (level > depth) now belong to $above[level - depth - 1]. */
    protected function fixBondIc(Bond $bond, int $depth, array $above, bool $apply, bool $reissuePaid, array &$report): void
    {
        $schedule = $bond->plan?->ic_schedule ?? [];
        if (empty($schedule)) {
            return;
        }
        $rows = CommissionLedger::where('bond_id', $bond->id)->where('type', 'IC')->get();
        if ($rows->isEmpty()) {
            return;   // no IC was ever issued on this bill (e.g. legacy bill, nothing to move)
        }

        // one IC "event" per bill / per Gold Saving instalment
        foreach ($rows->groupBy(fn ($r) => $r->invoice_no . '|' . substr((string) $r->earned_on, 0, 10)) as $group) {
            $first = $group->first();
            $earnedOn = substr((string) $first->earned_on, 0, 10);
            $base = $this->baseFor($bond, (string) $first->invoice_no);

            for ($level = $depth + 1; $level <= self::IC_LEVELS; $level++) {
                $pct = (float) ($schedule[$level - 1] ?? 0);
                $right = $above[$level - $depth - 1] ?? null;
                $atLevel = $group->where('level', $level);
                if ($right && $atLevel->contains(fn ($r) => (int) $r->member_id === (int) $right->id)) {
                    continue;   // already on the right upline
                }

                $blocked = false;
                foreach ($atLevel as $wrong) {
                    if ($wrong->status === 'pending') {
                        $report['ic_removed']++;
                        $report['ic_removed_amount'] += (float) $wrong->amount;
                        if ($apply) {
                            $wrong->delete();
                        }
                    } else {
                        $report['paid_wrong'][] = [
                            'invoice' => $first->invoice_no, 'buyer' => $bond->member?->member_code, 'level' => $level,
                            'paid_to' => Member::whereKey($wrong->member_id)->value('member_code'),
                            'should_be' => $right?->member_code ?? '—', 'amount' => (float) $wrong->amount, 'status' => $wrong->status,
                        ];
                        $blocked = ! $reissuePaid;
                    }
                }
                if ($blocked || ! $right || $pct <= 0 || $base <= 0) {
                    continue;
                }

                // same rule as billing: active upline, capped by that day's earning potential
                $amount = round($base * $pct / 100, 2);
                $amount = min($amount, round(app(CommissionService::class)->earningPotential($right, $earnedOn), 2));
                if ($amount <= 0) {
                    continue;
                }
                $report['ic_issued']++;
                $report['ic_issued_amount'] += $amount;
                if ($apply) {
                    $branch = $right->branch;
                    CommissionLedger::create([
                        'type' => 'IC',
                        'member_id' => $right->id,
                        'from_member_id' => $bond->member_id,
                        'bond_id' => $bond->id,
                        'invoice_no' => $first->invoice_no,
                        'level' => $level,
                        'amount' => $amount,
                        'currency_code' => $branch?->currency_code ?: 'INR',
                        'fx_rate' => $branch?->fxRateToBase() ?? 1.0,
                        'status' => 'pending',
                        'earned_on' => $earnedOn,
                        'branch_id' => $first->branch_id,
                    ]);
                }
            }
        }
    }

    /** The IC base billing used: the sale = pre-tax bill × fx; a Gold Saving instalment = the bond's billed value. */
    protected function baseFor(Bond $bond, string $reference): float
    {
        if ($reference === (string) $bond->invoice_no) {
            $inv = SalesInvoice::where('invoice_no', $reference)->first(['cross_total', 'fx_rate']);
            if ($inv) {
                return round((float) $inv->cross_total * (float) ($inv->fx_rate ?: 1), 2);
            }
        }

        return (float) ($bond->epin_value ?: $bond->value);
    }

    /** @return array<int,int> member_id => depth below $rootId (root = 0), down to $maxDepth - 1 */
    protected function subtree(int $rootId, int $maxDepth): array
    {
        $out = [$rootId => 0];
        $frontier = [$rootId];
        for ($d = 1; $d < $maxDepth && $frontier; $d++) {
            $frontier = Member::whereIn('upline_id', $frontier)->pluck('id')->all();
            foreach ($frontier as $id) {
                $out[$id] ??= $d;
            }
        }

        return $out;
    }
}
