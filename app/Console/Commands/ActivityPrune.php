<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/** Keep the activity log to a bounded size: rows older than --days are removed (scheduled monthly). */
class ActivityPrune extends Command
{
    protected $signature = 'activity:prune {--days=365 : Delete rows older than this many days}';

    protected $description = 'Delete activity-log rows older than N days';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $n = 0;
        do {
            $batch = ActivityLog::where('occurred_at', '<', $cutoff)->limit(5000)->delete();
            $n += $batch;
        } while ($batch > 0);
        $this->info("Activity log pruned: {$n} row(s) older than {$cutoff->toDateString()} removed.");

        return self::SUCCESS;
    }
}
