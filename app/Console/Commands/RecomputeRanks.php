<?php

namespace App\Console\Commands;

use App\Services\NetworkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The ranking pipeline trigger (legacy Ascript chain): expire bonds → bv → gbv →
 * unpure bv → unpure gbv → ranks. Safe to run repeatedly; results are deterministic.
 *
 *   php artisan ranks:recompute
 */
class RecomputeRanks extends Command
{
    protected $signature = 'ranks:recompute';

    protected $description = 'Recompute member BV/GBV and assign ranks (expire bonds → BV → GBV → unpure → ranks)';

    public function handle(NetworkService $network): int
    {
        $this->info('Ranking pipeline starting (' . now()->format('Y-m-d H:i T') . ')…');

        $startedAt = now();
        $network->recomputeAll();

        // every shift this run made is registered in rank_changes with the date (user 2026-11-04)
        $shifts = DB::table('rank_changes')->where('changed_at', '>=', $startedAt)
            ->selectRaw("SUM(CASE WHEN direction = 'up' THEN 1 ELSE 0 END) AS up, SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END) AS down")->first();
        $this->info(sprintf('Rank shifts registered: %d up, %d down — System → Rank Changes / Ranked Distributor.',
            (int) ($shifts->up ?? 0), (int) ($shifts->down ?? 0)));

        $dist = DB::table('members')
            ->leftJoin('ranks', 'ranks.id', '=', 'members.rank_id')
            ->select('ranks.code', 'ranks.depth', DB::raw('count(*) as c'))
            ->groupBy('ranks.code', 'ranks.depth')
            ->orderBy('ranks.depth')
            ->get();

        $this->table(
            ['Rank', 'Members'],
            $dist->map(fn ($d) => [$d->code ?? '—', $d->c])->all()
        );
        $this->info('Ranking pipeline complete.');

        return self::SUCCESS;
    }
}
