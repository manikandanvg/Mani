<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Rank;
use App\Services\NetworkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-04: "I need the existing distributors' rank achievement dates — calibrate."
 *
 * Legacy never stored when a distributor reached a TBP stage (tbl_achiever has no date), so the
 * dates are reconstructed by replaying the ranking rules over the bond history: on every day a
 * bond starts or expires, the ranking BV of every member is recomputed (same maths as
 * NetworkService: plan rank factor × bill, group roll-up, entry gate + leg tiers) and each
 * member's stage is compared with the day before. Every shift becomes a rank_changes row with
 * that date, source "calibration". The replay ends on today's stage, which is compared with the
 * live rank_id so any drift is reported.
 *
 *   php artisan ranks:calibrate                 dry run — counts per year, mismatches, sample
 *   php artisan ranks:calibrate --apply         replace the "calibration" rows with this replay
 *   php artisan ranks:calibrate --step=month    coarser replay (month-ends only)
 */
class RanksCalibrate extends Command
{
    protected $signature = 'ranks:calibrate {--apply : write the rank_changes rows (replaces earlier calibration rows)} {--step=day : day (every bond start/expiry date) or month (month-ends)} {--upto= : replay up to this date (default today)}';

    protected $description = 'Reconstruct every distributor\'s TBP-stage achievement dates by replaying the rank rules over the bond history';

    public function handle(NetworkService $network): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $apply = (bool) $this->option('apply');
        $upto = Carbon::parse((string) ($this->option('upto') ?: now()->toDateString()))->endOfDay();
        $byMonth = $this->option('step') === 'month';

        $ranks = Rank::where('is_active', true)->orderBy('depth')->get();
        $rankByDepth = $ranks->keyBy('depth');
        $memberRankId = (int) ($rankByDepth->get(0)?->id ?? $ranks->first()?->id);
        $depthOf = $ranks->pluck('depth', 'id')->all();

        $plans = Plan::get()->keyBy('id');
        $members = DB::table('members')->select('id', 'upline_id', 'rank_id')->get();
        $bonds = DB::table('bonds')->select('member_id', 'plan_id', 'bond_date', 'value', 'epin_value')
            ->where('status', '!=', 'cancelled')->whereNotNull('bond_date')->orderBy('bond_date')->get();

        // --- bonds → (start, end, ranking BV) ---------------------------------------------------
        $starts = [];   // 'Y-m-d' => [[member, unpure], ...]
        $ends = [];
        $first = null;
        foreach ($bonds as $b) {
            $plan = $plans->get($b->plan_id);
            if (! $plan) {
                continue;
            }
            $unpure = $plan->unpureFromBondValue((float) $b->value, (float) $b->epin_value);
            if ($unpure <= 0) {
                continue;
            }
            $start = Carbon::parse($b->bond_date)->startOfDay();
            if ($start->gt($upto)) {
                continue;
            }
            $validity = (int) (($plan->level_com_duration ?? 0) ?: ($plan->validity_months ?? 0));
            $end = $validity > 0 ? $start->copy()->addMonthsNoOverflow($validity) : null;   // expired once age ≥ validity
            $starts[$start->toDateString()][] = [(int) $b->member_id, $unpure];
            if ($end && $end->lte($upto)) {
                $ends[$end->toDateString()][] = [(int) $b->member_id, $unpure];
            }
            $first = $first === null || $start->lt($first) ? $start : $first;
        }
        if ($first === null) {
            $this->warn('No bonds to replay.');

            return self::SUCCESS;
        }

        // --- the days to evaluate --------------------------------------------------------------
        $days = array_unique(array_merge(array_keys($starts), array_keys($ends)));
        if ($byMonth) {
            $cursor = $first->copy()->endOfMonth();
            $days = [];
            while ($cursor->lte($upto)) {
                $days[] = $cursor->toDateString();
                $cursor = $cursor->addDay()->endOfMonth();
            }
        }
        $days[] = $upto->toDateString();
        $days = array_values(array_unique($days));
        sort($days);
        $this->info(sprintf('Replaying %d members, %d bonds over %d %s from %s to %s …',
            $members->count(), $bonds->count(), count($days), $byMonth ? 'month-ends' : 'event days', $first->toDateString(), $upto->toDateString()));

        // --- tree: children map + an order where every child comes before its parent -----------
        $upline = [];
        $children = [];
        foreach ($members as $m) {
            $upline[$m->id] = $m->upline_id ? (int) $m->upline_id : null;
            if ($m->upline_id) {
                $children[(int) $m->upline_id][] = (int) $m->id;
            }
        }
        $order = [];   // post-order (children first)
        $seen = [];
        foreach ($members as $m) {
            $id = (int) $m->id;
            if (isset($seen[$id])) {
                continue;
            }
            $stack = [[$id, false]];
            while ($stack) {
                [$node, $expanded] = array_pop($stack);
                if ($expanded) {
                    $order[] = $node;
                    continue;
                }
                if (isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;
                $stack[] = [$node, true];
                foreach ($children[$node] ?? [] as $child) {
                    if (! isset($seen[$child])) {
                        $stack[] = [$child, false];
                    }
                }
            }
        }

        // --- replay ------------------------------------------------------------------------------
        $unpure = array_fill_keys(array_keys($upline), 0.0);
        $depthNow = array_fill_keys(array_keys($upline), 0);
        $changes = [];   // rows to write
        $perYear = [];
        $eventDates = array_keys(array_merge($starts, $ends));
        sort($eventDates);
        $eventIdx = 0;
        $bar = $this->output->createProgressBar(count($days));
        foreach ($days as $day) {
            // apply every start/expiry up to and including this day
            while ($eventIdx < count($eventDates) && $eventDates[$eventIdx] <= $day) {
                $d = $eventDates[$eventIdx++];
                foreach ($starts[$d] ?? [] as [$mid, $u]) {
                    if (isset($unpure[$mid])) {
                        $unpure[$mid] += $u;
                    }
                }
                foreach ($ends[$d] ?? [] as [$mid, $u]) {
                    if (isset($unpure[$mid])) {
                        $unpure[$mid] = max(0.0, $unpure[$mid] - $u);
                    }
                }
            }
            // group BV roll-up (children first)
            $gbv = [];
            foreach ($order as $id) {
                $sum = 0.0;
                foreach ($children[$id] ?? [] as $c) {
                    $sum += $unpure[$c] + ($gbv[$c] ?? 0.0);
                }
                $gbv[$id] = $sum;
            }
            // stage per member
            $entry = (float) ($rankByDepth->get(1)?->target_bv ?? 50000);
            foreach ($order as $id) {
                $legs = [];
                $direct = 0;
                foreach ($children[$id] ?? [] as $c) {
                    $legs[] = $unpure[$c] + ($gbv[$c] ?? 0.0);
                    if ($unpure[$c] >= $entry) {
                        $direct++;
                    }
                }
                $depth = $unpure[$id] > 0 || $legs ? $network->computeDepth($unpure[$id], $legs, $direct) : 0;
                if ($depth !== $depthNow[$id]) {
                    $fromDepth = $depthNow[$id];
                    $changes[] = [
                        'member_id' => $id,
                        'from_rank_id' => (int) ($rankByDepth->get($fromDepth)?->id ?? $memberRankId),
                        'to_rank_id' => (int) ($rankByDepth->get($depth)?->id ?? $memberRankId),
                        'from_depth' => $fromDepth, 'to_depth' => $depth,
                        'direction' => $depth >= $fromDepth ? 'up' : 'down',
                        'unpure_bv' => round($unpure[$id], 2), 'unpure_gbv' => round($gbv[$id] ?? 0.0, 2),
                        'source' => 'calibration', 'changed_at' => $day . ' 00:00:00',
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $y = substr($day, 0, 4);
                    $perYear[$y][$depth >= $fromDepth ? 'up' : 'down'] = ($perYear[$y][$depth >= $fromDepth ? 'up' : 'down'] ?? 0) + 1;
                    $depthNow[$id] = $depth;
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // --- report ------------------------------------------------------------------------------
        $mismatch = 0;
        foreach ($members as $m) {
            $liveDepth = (int) ($depthOf[(int) $m->rank_id] ?? 0);
            if ($liveDepth !== $depthNow[(int) $m->id]) {
                $mismatch++;
            }
        }
        $rows = [];
        ksort($perYear);
        foreach ($perYear as $y => $c) {
            $rows[] = [$y, $c['up'] ?? 0, $c['down'] ?? 0];
        }
        $this->table(['Year', 'Promotions', 'Demotions'], $rows);
        $ranked = count(array_filter($depthNow, fn ($d) => $d >= 1));
        $this->info(sprintf('%d shifts reconstructed; %d members end ranked; %d member(s) differ from the live rank_id (bonds cancelled/closed by hand, or ranks:recompute not run since the last change).',
            count($changes), $ranked, $mismatch));
        foreach (array_slice(array_filter($changes, fn ($c) => $c['to_depth'] >= 1), -5) as $c) {
            $this->line(sprintf('  e.g. member %d → depth %d on %s (own BV ₹%s)', $c['member_id'], $c['to_depth'], substr($c['changed_at'], 0, 10), number_format($c['unpure_bv'])));
        }

        if (! $apply) {
            $this->line('Dry run — nothing written. Run with --apply to register these dates.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes) {
            DB::table('rank_changes')->where('source', 'calibration')->delete();
            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('rank_changes')->insert($chunk);
            }
        });
        $this->info('Registered ' . count($changes) . ' calibration rows — see System → Ranked Distributor / Rank Changes.');

        return self::SUCCESS;
    }
}
