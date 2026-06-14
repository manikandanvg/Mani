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

        $network->recomputeAll();

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
