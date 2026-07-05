<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Plan;
use App\Models\Rank;
use App\Services\Payroll\EmployeeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the MLM network aggregates on tbl members:
 *   bv         personal Business Volume (Σ active bond value)
 *   gbv        group BV (Σ bv over the whole downline subtree)
 *   unpure_bv  allocation-adjusted "real/withdrawable" BV
 *   unpure_gbv group unpure BV
 *   downline_count + rank
 *
 * DB-agnostic: the tree maths run in PHP over an in-memory map, so it behaves
 * identically on MySQL (prod) and SQLite (tests). Ordered so ranking — which
 * reads unpure values — runs last.
 */
class NetworkService
{
    protected ?array $rankCfg = null;

    /** Run the full chain in the legacy order (bonds → bv → gbv → unpure bv → unpure gbv → ranks). */
    public function recomputeAll(?Carbon $asOf = null): void
    {
        $this->deactivateExpiredBonds($asOf);   // legacy bondallocation
        $this->recomputePersonalBv();
        $this->recomputeGroupBv();              // gbv + downline_count
        $this->recomputeUnpureBv();
        $this->recomputeUnpureGbv();
        $this->recomputeRanks();
    }

    /**
     * Legacy bondallocation: a bond stays 'active' until it reaches its plan's validity
     * (level_com_duration, else validity_months) in whole months from bond_date; past that
     * it becomes 'closed' and stops counting toward BV. 'cancelled' bonds are left untouched.
     */
    public function deactivateExpiredBonds(?Carbon $asOf = null): void
    {
        $asOf = $asOf ?: Carbon::now();
        $plans = Plan::get()->keyBy('id');

        DB::table('bonds')
            ->whereIn('status', ['active', 'closed'])
            ->select('id', 'plan_id', 'bond_date', 'status')
            ->orderBy('id')
            ->chunk(2000, function ($bonds) use ($plans, $asOf) {
                $close = [];
                $open = [];
                foreach ($bonds as $b) {
                    $plan = $plans->get($b->plan_id);
                    $validity = (int) (($plan->level_com_duration ?? 0) ?: ($plan->validity_months ?? 0));
                    $age = $b->bond_date ? Carbon::parse($b->bond_date)->diffInMonths($asOf) : 0;
                    $expired = $validity > 0 && $age >= $validity;

                    if ($expired && $b->status !== 'closed') {
                        $close[] = $b->id;
                    } elseif (! $expired && $b->status !== 'active') {
                        $open[] = $b->id;
                    }
                }
                if ($close) {
                    DB::table('bonds')->whereIn('id', $close)->update(['status' => 'closed']);
                }
                if ($open) {
                    DB::table('bonds')->whereIn('id', $open)->update(['status' => 'active']);
                }
            });
    }

    /** bv = Σ value of the member's ACTIVE bonds. */
    public function recomputePersonalBv(): void
    {
        DB::table('members')->update(['bv' => 0]);

        $sums = DB::table('bonds')
            ->select('member_id', DB::raw('SUM(value) as total'))
            ->where('status', 'active')
            ->groupBy('member_id')
            ->pluck('total', 'member_id');

        foreach ($sums as $memberId => $total) {
            DB::table('members')->where('id', $memberId)->update(['bv' => $total]);
        }
    }

    /** unpure_bv = Σ (bond.value × ranking factor) of active bonds — the legacy rabvreset. */
    public function recomputeUnpureBv(): void
    {
        $plans = Plan::get()->keyBy('id');

        DB::table('members')->update(['unpure_bv' => 0]);

        $rows = DB::table('bonds')
            ->select('member_id', 'plan_id', 'value')
            ->where('status', 'active')
            ->get();

        $totals = [];
        foreach ($rows as $r) {
            $factor = $this->rankFactor($plans->get($r->plan_id));
            if ($factor <= 0) {
                continue;
            }
            $totals[$r->member_id] = ($totals[$r->member_id] ?? 0) + ((float) $r->value * $factor);
        }

        foreach ($totals as $memberId => $total) {
            DB::table('members')->where('id', $memberId)->update(['unpure_bv' => round($total, 2)]);
        }
    }

    /** Ranking-BV factor for a plan; the rule lives on the Plan model. */
    protected function rankFactor(?Plan $plan): float
    {
        return $plan ? $plan->rankFactor() : 0.0;
    }

    public function recomputeGroupBv(): void
    {
        $this->rollUpSubtree('bv', 'gbv', withCount: true);
    }

    public function recomputeUnpureGbv(): void
    {
        $this->rollUpSubtree('unpure_bv', 'unpure_gbv');
    }

    /**
     * For every member, set $targetCol = Σ $sourceCol over its entire downline
     * subtree (excluding self). Optionally also set downline_count.
     */
    protected function rollUpSubtree(string $sourceCol, string $targetCol, bool $withCount = false): void
    {
        $members = DB::table('members')->select('id', 'upline_id', $sourceCol)->get();

        $children = [];          // upline_id => [child ids]
        $value = [];             // id => source value
        foreach ($members as $m) {
            $children[$m->upline_id][] = $m->id;
            $value[$m->id] = (float) $m->{$sourceCol};
        }

        $sumCache = [];          // id => [sum, count]
        $resolve = function ($id) use (&$resolve, &$children, &$value, &$sumCache) {
            if (isset($sumCache[$id])) {
                return $sumCache[$id];
            }
            $sum = 0.0;
            $count = 0;
            foreach ($children[$id] ?? [] as $childId) {
                [$childSum, $childCount] = $resolve($childId);
                $sum += $value[$childId] + $childSum;
                $count += 1 + $childCount;
            }
            return $sumCache[$id] = [$sum, $count];
        };

        foreach ($value as $id => $_) {
            [$sum, $count] = $resolve($id);
            $update = [$targetCol => round($sum, 2)];
            if ($withCount) {
                $update['downline_count'] = $count;
            }
            DB::table('members')->where('id', $id)->update($update);
        }
    }

    /**
     * Legacy furnishlegrw ranking. Two gates:
     *  1) Entry (→ depth 1, Taluk): own unpure_bv ≥ entry AND ≥1 DIRECT downline with unpure_bv ≥ entry.
     *  2) Balanced legs: take the top-5 legs by (unpure_bv + unpure_gbv) and, for each higher tier,
     *     greedily match its tier_template thresholds to DISTINCT legs. Highest qualifying depth wins.
     * All thresholds live in the ranks table (entry = Taluk.target_bv; legs = tier_template).
     */
    public function recomputeRanks(): void
    {
        $ranks = Rank::where('is_active', true)->orderBy('depth')->get();
        $byDepth = $ranks->keyBy('depth');
        $memberRankId = $byDepth->get(0)?->id ?? $ranks->first()?->id;
        $entry = (float) ($byDepth->get(1)?->target_bv ?? 50000);
        $tiers = $ranks->filter(fn ($r) => $r->depth >= 2 && ! empty($r->tier_template))
            ->sortBy('depth')->values();

        Member::query()->update(['rank_id' => $memberRankId]);

        $all = DB::table('members')->select('id', 'upline_id', 'unpure_bv', 'unpure_gbv')->get();

        $legsByParent = [];      // parent id => [leg business values]
        $directQualified = [];   // parent id => count of direct downlines with unpure_bv >= entry
        $selfBv = [];
        foreach ($all as $m) {
            $selfBv[$m->id] = (float) $m->unpure_bv;
            if ($m->upline_id) {
                $legsByParent[$m->upline_id][] = (float) $m->unpure_bv + (float) $m->unpure_gbv;
                if ((float) $m->unpure_bv >= $entry) {
                    $directQualified[$m->upline_id] = ($directQualified[$m->upline_id] ?? 0) + 1;
                }
            }
        }

        $assign = [];   // rank_id => [member ids]
        foreach ($all as $m) {
            $depth = $this->computeDepth(
                $selfBv[$m->id],
                $legsByParent[$m->id] ?? [],
                $directQualified[$m->id] ?? 0
            );
            if ($depth > 0) {
                $rankId = $byDepth->get($depth)?->id ?? $memberRankId;
                if ($rankId !== $memberRankId) {
                    $assign[$rankId][] = $m->id;
                }
            }
        }

        foreach ($assign as $rankId => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('members')->whereIn('id', $chunk)->update(['rank_id' => $rankId]);
            }
        }

        // Payroll mandate: anyone now holding a TBP stage becomes an employee.
        app(EmployeeService::class)->syncFromRanks();
    }

    /**
     * Re-evaluate ONE member's rank from current unpure values — the incremental path used
     * at billing. Same maths as the batch recomputeRanks, so the two never diverge.
     */
    public function evaluateRank(int $memberId): void
    {
        $cfg = $this->rankConfig();
        $m = DB::table('members')->select('id', 'unpure_bv')->find($memberId);
        if (! $m) {
            return;
        }

        $children = DB::table('members')->select('unpure_bv', 'unpure_gbv')
            ->where('upline_id', $memberId)->get();

        $directQualified = 0;
        $legs = [];
        foreach ($children as $c) {
            if ((float) $c->unpure_bv >= $cfg['entry']) {
                $directQualified++;
            }
            $legs[] = (float) $c->unpure_bv + (float) $c->unpure_gbv;
        }

        $depth = $this->computeDepth((float) $m->unpure_bv, $legs, $directQualified);
        $rankId = $cfg['byDepth']->get($depth)?->id ?? $cfg['memberRankId'];
        DB::table('members')->where('id', $memberId)->update(['rank_id' => $rankId]);

        // Payroll mandate: promotion onto a TBP stage auto-enrols the member as an employee.
        if ($depth >= 1) {
            app(EmployeeService::class)->autoEnroll($memberId);
        }
    }

    /**
     * Pure rank depth: entry gate (self ≥ entry AND ≥1 direct downline ≥ entry → depth 1),
     * then the highest tier whose tier_template is satisfied by distinct top-5 legs.
     */
    public function computeDepth(float $selfUnpureBv, array $legValues, int $directQualified): int
    {
        $cfg = $this->rankConfig();
        if ($selfUnpureBv < $cfg['entry'] || $directQualified < 1) {
            return 0;
        }
        $depth = 1;
        rsort($legValues);
        $legs = array_slice($legValues, 0, 5);
        foreach ($cfg['tiers'] as $tier) {
            if ($this->qualifiesLegs($legs, $tier->tier_template)) {
                $depth = max($depth, (int) $tier->depth);
            }
        }

        return $depth;
    }

    /** Rank thresholds, memoised per instance (entry gate + leg tiers + depth→id map). */
    protected function rankConfig(): array
    {
        if ($this->rankCfg !== null) {
            return $this->rankCfg;
        }
        $ranks = Rank::where('is_active', true)->orderBy('depth')->get();
        $byDepth = $ranks->keyBy('depth');

        return $this->rankCfg = [
            'byDepth' => $byDepth,
            'memberRankId' => $byDepth->get(0)?->id ?? $ranks->first()?->id,
            'entry' => (float) ($byDepth->get(1)?->target_bv ?? 50000),
            'tiers' => $ranks->filter(fn ($r) => $r->depth >= 2 && ! empty($r->tier_template))->sortBy('depth')->values(),
        ];
    }

    /**
     * Greedy balanced-leg test: each positive threshold in the template must be met by a
     * DISTINCT leg (one big leg cannot satisfy two requirements). Legs are pre-sorted desc.
     */
    protected function qualifiesLegs(array $legs, ?array $template): bool
    {
        if (empty($template)) {
            return false;
        }
        $used = [];
        foreach ($template as $req) {
            $req = (float) $req;
            if ($req <= 0) {
                continue;
            }
            $found = false;
            foreach ($legs as $i => $val) {
                if (! in_array($i, $used, true) && $val >= $req) {
                    $used[] = $i;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
