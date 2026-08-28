<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Monthly Tasks Engine jobs (board 2026-08-29):
 *
 *   php artisan tasks:run --only=roll            the 1st — assignments from the rules + GBV baseline
 *   php artisan tasks:run --only=measure         nightly — progress of the current month
 *   php artisan tasks:run --only=close           20:00 — auto-close open branches + stock snapshot
 *   php artisan tasks:run --only=lock            the 1st — lock LAST month, write scores
 *   php artisan tasks:run                        roll + measure (safe any time)
 *   --month=YYYY-MM                              operate on a specific month
 */
class TasksRun extends Command
{
    protected $signature = 'tasks:run {--only= : roll|measure|close|lock} {--month= : YYYY-MM}';

    protected $description = 'Monthly tasks: roll assignments, measure progress, auto-close branches, lock scores';

    public function handle(TaskEngine $engine): int
    {
        $only = $this->option('only');
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth() : null;

        if ($only === 'close') {
            $closed = $engine->autoClose();
            $snap = $engine->snapshotStock();
            $this->info("Auto-closed {$closed} branch day(s); stock snapshot rows: {$snap}.");

            return self::SUCCESS;
        }

        if ($only === 'lock') {
            $target = $month ?? Carbon::now()->subMonth()->startOfMonth();
            $n = $engine->lockMonth($target);
            $this->info("Locked {$target->format('F Y')}: {$n} score(s) written.");

            return self::SUCCESS;
        }

        if (! $only || $only === 'roll') {
            $n = $engine->rollMonth($month);
            $this->info("Rolled: {$n} assignment(s) created.");
        }
        if (! $only || $only === 'measure') {
            $n = $engine->measure($month);
            $this->info("Measured {$n} assignment(s).");
        }

        return self::SUCCESS;
    }
}
