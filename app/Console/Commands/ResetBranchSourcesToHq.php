<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Activity\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-11: "make all branches request only with HQ — don't map another dealer branch
 * until I confirm". Clears every supplier link that points at a dealer (they came from the
 * legacy import, which recorded each dealer's SPONSOR as its supplier), so every order goes
 * to Head Office. The old links are saved to a CSV first, so they can be put back.
 *
 *   php artisan branches:reset-sources-to-hq            # report only
 *   php artisan branches:reset-sources-to-hq --apply    # clear them (backup CSV written first)
 *   php artisan branches:reset-sources-to-hq --restore=storage/app/branch-sources-backup-….csv
 *
 * Together with HQ_ONLY_SOURCING (config/features.php, on by default) nothing can re-map a
 * branch to a dealer until that switch is turned off.
 */
class ResetBranchSourcesToHq extends Command
{
    protected $signature = 'branches:reset-sources-to-hq
        {--apply : Clear the dealer supplier links (default: report only)}
        {--restore= : Put back the links saved in this backup CSV}';

    protected $description = 'Point every branch at Head Office as its only stock supplier';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            return $this->restore($file);
        }

        $hqIds = Branch::where('level', 'hq')->pluck('id')->all() ?: [1];
        $rows = DB::table('branches as b')->join('branches as s', 's.id', '=', 'b.source_branch_id')
            ->whereNull('b.deleted_at')->whereNotIn('b.source_branch_id', $hqIds)
            ->orderBy('b.id')
            ->get(['b.id', 'b.name', 'b.incharge', 'b.level', 'b.source_branch_id', 's.name as source_name', 's.incharge as source_incharge', 's.level as source_level']);

        $this->info($rows->count() . ' branch(es) are mapped to a dealer as supplier.');
        foreach ($rows->groupBy('source_level') as $level => $g) {
            $this->line(sprintf('  supplier level %-12s %d', $level ?: '(none)', $g->count()));
        }
        if ($rows->isEmpty() || ! $this->option('apply')) {
            $this->line($rows->isEmpty() ? 'Nothing to do — every branch already orders from Head Office.' : 'Re-run with --apply to clear them (a backup CSV is written first).');

            return self::SUCCESS;
        }

        $path = storage_path('app/branch-sources-backup-' . now()->format('Ymd-His') . '.csv');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['branch_id', 'branch', 'incharge', 'level', 'source_branch_id', 'source', 'source_incharge', 'source_level']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r->id, $r->name, $r->incharge, $r->level, $r->source_branch_id, $r->source_name, $r->source_incharge, $r->source_level]);
        }
        fclose($fh);

        $n = DB::table('branches')->whereIn('id', $rows->pluck('id'))->update(['source_branch_id' => null, 'updated_at' => now()]);
        ActivityLogger::log('branch', 'sources_reset_to_hq', $n . ' branch supplier link(s) cleared — every branch now orders from Head Office (user 2026-11-11)', [
            'details' => ['count' => $n, 'backup' => basename($path)],
        ]);
        $this->info($n . ' link(s) cleared. Backup: ' . $path);

        return self::SUCCESS;
    }

    protected function restore(string $file): int
    {
        if (! is_file($file)) {
            $this->error('Backup not found: ' . $file);

            return self::FAILURE;
        }
        $fh = fopen($file, 'r');
        fgetcsv($fh);
        $n = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (! empty($row[0]) && ! empty($row[4])) {
                $n += DB::table('branches')->where('id', (int) $row[0])->update(['source_branch_id' => (int) $row[4]]);
            }
        }
        fclose($fh);
        $this->info($n . ' supplier link(s) restored from ' . $file . '. (They take effect only with HQ_ONLY_SOURCING=false.)');

        return self::SUCCESS;
    }
}
