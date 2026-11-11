<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityLogger;
use App\Services\Geo\HierarchyMapper;
use Illuminate\Console\Command;

/**
 * Board 2026-10-31: auto-map the dealer hierarchy by pincode — every dealer's stock
 * source (supplier) becomes the nearest allowed upper-level dealer in the same pincode /
 * taluka / district / state, else Head Office.
 *
 *   php artisan branches:auto-map                 # proposals only (nothing written)
 *   php artisan branches:auto-map --only-unmapped # proposals for dealers that have no supplier yet
 *   php artisan branches:auto-map --apply         # write the proposed suppliers
 */
class BranchesAutoMap extends Command
{
    protected $signature = 'branches:auto-map
        {--apply : Write the proposed supplier onto each dealer (default: report only)}
        {--only-unmapped : Only dealers without a supplier}';

    protected $description = 'Map every dealer to its supplier by pincode (taluka / district / state locality)';

    public function handle(HierarchyMapper $mapper): int
    {
        $rows = $mapper->proposeBranches((bool) $this->option('only-unmapped'));
        $this->table(['#', 'Dealer', 'Level', 'Pincode', 'Current supplier', 'Proposed supplier', 'Why', 'Score'],
            array_map(fn ($r) => [
                $r['branch']->id, \Illuminate\Support\Str::limit($r['branch']->name, 28), $r['branch']->level, $r['branch']->pincode,
                $r['current']?->name ?? '—', $r['proposed']?->name ?? '—', $r['reason'], $r['score'],
            ], $rows));

        $changes = array_filter($rows, fn ($r) => $r['changes']);
        $this->info(count($rows) . ' dealer(s) · ' . count($changes) . ' would change supplier.');

        if (! $this->option('apply')) {
            $this->line('Re-run with --apply to write them.');

            return self::SUCCESS;
        }
        if (\App\Models\Branch::hqOnlySourcing()) {
            $this->error('Not written: all branches order from Head Office only (HQ_ONLY_SOURCING, user 2026-11-11).');

            return self::FAILURE;
        }
        foreach ($changes as $r) {
            $b = $r['branch'];
            $from = $r['current']?->name ?? '—';
            $b->update(['source_branch_id' => $r['proposed']->id]);
            ActivityLogger::log('branch', 'auto_mapped', "Supplier of {$b->name} set to {$r['proposed']->name} by pincode ({$r['reason']}; was {$from})", [
                'subject' => $b, 'details' => ['from' => $r['current']?->id, 'to' => $r['proposed']->id, 'reason' => $r['reason'], 'score' => $r['score']],
            ]);
        }
        $this->info(count($changes) . ' supplier(s) written. Review: Master → Branches (Supplier column) · `branches:audit-sources`.');

        return self::SUCCESS;
    }
}
