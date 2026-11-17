<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Rank;
use App\Services\NetworkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-17: "a lot of people say their BV, GBV, ranking BV and ranking GBV are missing
 * or not showing properly — re-audit and settle each one."
 *
 * Every distributor's four volumes (and TBP stage, team size) are worked out afresh from the
 * contracts on record — the same rules as the nightly run (NetworkService):
 *
 *   BV           Σ full bill of the distributor's ACTIVE contracts
 *   GBV          Σ BV of everyone below
 *   Ranking BV   Σ full bill × the scheme's TBP % (G10 Gold / PLUS 1 excluded)
 *   Ranking GBV  Σ ranking BV of everyone below
 *
 * and compared with what the distributor sees today. Nothing is written without --apply.
 *
 *   php artisan bv:audit                      dry run — who differs, by how much, why; CSV report
 *   php artisan bv:audit --member=LJW24701    one distributor's statement, contract by contract
 *   php artisan bv:audit --apply              settle: write the correct figures + stages (one transaction)
 */
class BvAudit extends Command
{
    protected $signature = 'bv:audit
        {--member= : distributor code — print the contract-by-contract statement of this one}
        {--apply : write the correct figures (runs the full recompute, then audits again)}
        {--out= : where to write the CSV (default storage/app/reports)}';

    protected $description = 'Audit every distributor\'s BV / GBV / ranking BV / ranking GBV against the contracts on record, and settle them';

    protected const COLS = ['bv' => 'BV', 'gbv' => 'GBV', 'unpure_bv' => 'Ranking BV', 'unpure_gbv' => 'Ranking GBV'];

    public function handle(NetworkService $network): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        if ($code = $this->option('member')) {
            return $this->statement((string) $code, $network);
        }

        $diffs = $this->audit($network);
        $this->report($diffs);

        if (! $this->option('apply')) {
            $this->line($diffs['rows'] === []
                ? 'Nothing to settle.'
                : 'Dry run — nothing written. Run with --apply to settle these figures.');

            return self::SUCCESS;
        }

        $this->info('Settling …');
        $network->recomputeAll();
        $after = $this->audit($network);
        $this->info(sprintf('Settled. %d distributor(s) still differ%s.', count($after['rows']),
            $after['rows'] === [] ? ' — every figure now equals the contracts on record' : ' (run the audit again and check the CSV)'));

        return self::SUCCESS;
    }

    /**
     * Fresh figures for every member, worked out in memory.
     *
     * @return array{rows: array<int, array>, fresh: array, expiring: array<int, array>, members: \Illuminate\Support\Collection}
     */
    protected function audit(NetworkService $network): array
    {
        $fresh = $this->fresh($network);
        $members = DB::table('members')
            ->select('id', 'member_code', 'name', 'phone', 'upline_id', 'rank_id', 'bv', 'gbv', 'unpure_bv', 'unpure_gbv', 'downline_count', 'deleted_at')
            ->get();

        $rows = [];
        foreach ($members as $m) {
            $f = $fresh['figures'][$m->id];
            $delta = [];
            foreach (array_keys(self::COLS) as $col) {
                $d = round($f[$col] - (float) $m->{$col}, 2);
                if (abs($d) >= 0.005) {
                    $delta[$col] = $d;
                }
            }
            $stageNow = (int) ($fresh['depthOfRank'][(int) $m->rank_id] ?? 0);
            $stageDiff = $stageNow !== $f['depth'];
            $teamDiff = (int) $m->downline_count !== $f['downline_count'];
            if (! $delta && ! $stageDiff && ! $teamDiff) {
                continue;
            }
            $rows[] = ['member' => $m, 'fresh' => $f, 'delta' => $delta, 'stage_now' => $stageNow,
                'reason' => $this->reason($m, $f, $delta, $fresh['expiring'][$m->id] ?? [])];
        }

        return ['rows' => $rows, 'fresh' => $fresh, 'members' => $members];
    }

    /** Why this distributor's figures differ — in words the support desk can repeat. */
    protected function reason(object $m, array $f, array $delta, array $expiring): string
    {
        $own = array_intersect_key($delta, ['bv' => 1, 'unpure_bv' => 1]);
        $lostAll = ((float) $m->bv == 0.0 && $f['bv'] > 0) || ((float) $m->gbv == 0.0 && $f['gbv'] > 0)
            || ((float) $m->unpure_bv == 0.0 && $f['unpure_bv'] > 0) || ((float) $m->unpure_gbv == 0.0 && $f['unpure_gbv'] > 0);

        return match (true) {
            $lostAll => 'figure shows 0 although contracts are active — blanked by an interrupted recompute',
            $own && $expiring !== [] => count($expiring) . ' own contract(s) reached the end of their period — no longer count',
            (bool) $own => 'own contracts changed (billed / cancelled / re-stated) after the figure was stored',
            (bool) $delta => 'team figure — a contract below changed (billed, cancelled or period over)',
            default => 'TBP stage / team size not refreshed',
        };
    }

    /**
     * @return array{figures: array<int, array>, expiring: array<int, array>, depthOfRank: array<int, int>, bonds: array<int, array>}
     */
    protected function fresh(NetworkService $network): array
    {
        $asOf = Carbon::now();
        $plans = Plan::get()->keyBy('id');
        $ranks = Rank::where('is_active', true)->orderBy('depth')->get();
        $entry = (float) ($ranks->firstWhere('depth', 1)?->target_bv ?? 50000);

        $bv = $unpure = $expiring = $bondsOf = [];
        $bonds = DB::table('bonds')->select('id', 'member_id', 'plan_id', 'invoice_no', 'bond_date', 'value', 'epin_value', 'status')
            ->orderBy('bond_date')->get();
        foreach ($bonds as $b) {
            $plan = $plans->get($b->plan_id);
            $validity = (int) (($plan->level_com_duration ?? 0) ?: ($plan->validity_months ?? 0));
            $start = $b->bond_date ? Carbon::parse($b->bond_date) : null;
            $expired = $validity > 0 && $start && $start->diffInMonths($asOf) >= $validity;
            $counts = in_array($b->status, ['active', 'closed'], true) && ! $expired;
            $bill = $plan ? $plan->billValueFromBond((float) $b->value, (float) $b->epin_value) : (float) ($b->epin_value ?: $b->value);
            $rank = $plan ? $plan->unpureFromBondValue((float) $b->value, (float) $b->epin_value) : 0.0;

            $bondsOf[$b->member_id][] = [
                'invoice' => $b->invoice_no, 'plan' => $plan?->code, 'date' => $start?->toDateString(), 'bill' => $bill,
                'factor' => $plan ? $plan->rankFactor() * 100 : 0.0, 'ranking' => $rank, 'status' => $b->status,
                'ends' => $validity > 0 && $start ? $start->copy()->addMonthsNoOverflow($validity)->toDateString() : null,
                'counts' => $counts,
            ];
            if ($expired && $b->status === 'active') {
                $expiring[$b->member_id][] = $b->id;   // still marked active, period over
            }
            if (! $counts) {
                continue;
            }
            $bv[$b->member_id] = ($bv[$b->member_id] ?? 0) + $bill;
            $unpure[$b->member_id] = ($unpure[$b->member_id] ?? 0) + $rank;
        }

        // tree, children before parents
        $children = $ids = [];
        foreach (DB::table('members')->select('id', 'upline_id')->get() as $m) {
            $ids[] = (int) $m->id;
            if ($m->upline_id) {
                $children[(int) $m->upline_id][] = (int) $m->id;
            }
        }
        $order = $seen = [];
        foreach ($ids as $id) {
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

        $figures = [];
        foreach ($order as $id) {
            $gbv = $ugbv = 0.0;
            $team = $direct = 0;
            $legs = [];
            foreach ($children[$id] ?? [] as $c) {
                $gbv += $figures[$c]['bv'] + $figures[$c]['gbv'];
                $ugbv += $figures[$c]['unpure_bv'] + $figures[$c]['unpure_gbv'];
                $team += 1 + $figures[$c]['downline_count'];
                $legs[] = $figures[$c]['unpure_bv'] + $figures[$c]['unpure_gbv'];
                if ($figures[$c]['unpure_bv'] >= $entry) {
                    $direct++;
                }
            }
            $own = round($unpure[$id] ?? 0.0, 2);
            $figures[$id] = [
                'bv' => round($bv[$id] ?? 0.0, 2), 'gbv' => round($gbv, 2),
                'unpure_bv' => $own, 'unpure_gbv' => round($ugbv, 2),
                'downline_count' => $team, 'depth' => $network->computeDepth($own, $legs, $direct),
            ];
        }

        return ['figures' => $figures, 'expiring' => $expiring, 'bonds' => $bondsOf,
            'depthOfRank' => Rank::pluck('depth', 'id')->map(fn ($d) => (int) $d)->all()];
    }

    protected function report(array $audit): void
    {
        $rows = $audit['rows'];
        $total = $audit['members']->count();
        $this->info(sprintf('%d distributors audited — %d differ from the contracts on record.', $total, count($rows)));

        $summary = [];
        foreach (self::COLS as $col => $label) {
            $hit = array_filter($rows, fn ($r) => isset($r['delta'][$col]));
            $short = array_sum(array_map(fn ($r) => max(0, $r['delta'][$col]), $hit));
            $over = array_sum(array_map(fn ($r) => max(0, -$r['delta'][$col]), $hit));
            $zero = count(array_filter($hit, fn ($r) => (float) $r['member']->{$col} == 0.0));
            $summary[] = [$label, count($hit), $zero, '₹' . \App\Support\Money::group($short), '₹' . \App\Support\Money::group($over)];
        }
        $stage = count(array_filter($rows, fn ($r) => $r['stage_now'] !== $r['fresh']['depth']));
        $this->table(['Figure', 'Distributors differing', 'Showing 0 now', 'Shown too little by', 'Shown too much by'], $summary);
        $this->line("TBP stage differs for {$stage} distributor(s).");

        $why = [];
        foreach ($rows as $r) {
            $why[$r['reason']] = ($why[$r['reason']] ?? 0) + 1;
        }
        arsort($why);
        $this->table(['Why', 'Distributors'], array_map(fn ($k, $v) => [$k, $v], array_keys($why), $why));

        if ($rows === []) {
            return;
        }

        usort($rows, fn ($a, $b) => array_sum(array_map('abs', $b['delta'])) <=> array_sum(array_map('abs', $a['delta'])));
        $path = $this->option('out') ?: storage_path('app/reports/bv-audit-' . now()->format('Y-m-d-His') . '.csv');
        @mkdir(dirname($path), 0775, true);
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Distributor code', 'Name', 'Phone',
            'BV shown', 'BV correct', 'GBV shown', 'GBV correct',
            'Ranking BV shown', 'Ranking BV correct', 'Ranking GBV shown', 'Ranking GBV correct',
            'Team shown', 'Team correct', 'Stage shown', 'Stage correct', 'Why']);
        foreach ($rows as $r) {
            $m = $r['member'];
            $f = $r['fresh'];
            fputcsv($fh, [$m->member_code, $m->name, $m->phone,
                $m->bv, $f['bv'], $m->gbv, $f['gbv'], $m->unpure_bv, $f['unpure_bv'], $m->unpure_gbv, $f['unpure_gbv'],
                $m->downline_count, $f['downline_count'], $r['stage_now'], $f['depth'], $r['reason']]);
        }
        fclose($fh);

        $this->table(['Distributor', 'BV shown → correct', 'GBV shown → correct', 'Ranking BV', 'Ranking GBV', 'Why'],
            array_map(function ($r) {
                $m = $r['member'];
                $f = $r['fresh'];
                $pair = fn (string $c) => isset($r['delta'][$c])
                    ? \App\Support\Money::group((float) $m->{$c}, 0) . ' → ' . \App\Support\Money::group($f[$c], 0) : '=';

                return [$m->member_code, $pair('bv'), $pair('gbv'), $pair('unpure_bv'), $pair('unpure_gbv'), mb_strimwidth($r['reason'], 0, 60, '…')];
            }, array_slice($rows, 0, 15)));
        $this->line('Full list: ' . $path);
    }

    /** One distributor, contract by contract — what counts, what does not, and why. */
    protected function statement(string $code, NetworkService $network): int
    {
        $m = DB::table('members')->where('member_code', $code)->first();
        if (! $m) {
            $this->error("No distributor with code {$code}.");

            return self::FAILURE;
        }
        $fresh = $this->fresh($network);
        $f = $fresh['figures'][$m->id];

        $this->info("{$m->member_code} — {$m->name}");
        $this->table(['Invoice', 'Scheme', 'Billed on', 'Bill (BV)', 'TBP %', 'Ranking BV', 'Period ends', 'Counts today?'],
            array_map(fn ($b) => [$b['invoice'], $b['plan'], $b['date'], \App\Support\Money::group($b['bill']),
                rtrim(rtrim(number_format($b['factor'], 2), '0'), '.') . '%', \App\Support\Money::group($b['ranking']), $b['ends'] ?? '—',
                $b['counts'] ? 'yes' : ($b['status'] === 'cancelled' ? 'no — cancelled' : 'no — period over')],
                $fresh['bonds'][$m->id] ?? []));

        $rows = [];
        foreach (self::COLS as $col => $label) {
            $rows[] = [$label, \App\Support\Money::group((float) $m->{$col}), \App\Support\Money::group($f[$col]),
                abs($f[$col] - (float) $m->{$col}) < 0.005 ? 'correct' : 'DIFFERS — settle with bv:audit --apply'];
        }
        $rows[] = ['Team size', $m->downline_count, $f['downline_count'], (int) $m->downline_count === $f['downline_count'] ? 'correct' : 'DIFFERS'];
        $stageNow = (int) ($fresh['depthOfRank'][(int) $m->rank_id] ?? 0);
        $rows[] = ['TBP stage (depth)', $stageNow, $f['depth'], $stageNow === $f['depth'] ? 'correct' : 'DIFFERS'];
        $this->table(['Figure', 'Shown today', 'From the contracts', ''], $rows);

        $legs = DB::table('members')->where('upline_id', $m->id)->get(['id', 'member_code', 'name']);
        $this->table(['Direct distributor', 'Name', 'Own ranking BV', 'Leg (own + team) ranking BV'],
            $legs->map(fn ($c) => [$c->member_code, $c->name, \App\Support\Money::group($fresh['figures'][$c->id]['unpure_bv']),
                \App\Support\Money::group($fresh['figures'][$c->id]['unpure_bv'] + $fresh['figures'][$c->id]['unpure_gbv'])])
                ->sortByDesc(fn ($r) => (float) str_replace(',', '', $r[3]))->take(10)->values()->all());

        return self::SUCCESS;
    }
}
