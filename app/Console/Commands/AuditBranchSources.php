<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\DealershipService;
use Illuminate\Console\Command;

/**
 * One-time / periodic check for the dealership ladder (board 2026-08-26): lists every
 * branch whose current stock source is not allowed for its level, so HQ can re-point
 * them on the Branch form before the allow-list is relied on. Nothing is changed.
 */
class AuditBranchSources extends Command
{
    protected $signature = 'branches:audit-sources';

    protected $description = 'List branches whose stock source is not in their level\'s buys-from allow-list';

    public function handle(DealershipService $svc): int
    {
        $rows = $svc->auditSources();
        if (empty($rows)) {
            $this->info('All branch sources comply with the dealership ladder.');

            return self::SUCCESS;
        }

        $this->table(
            ['Branch', 'Level', 'Current source', 'Source level', 'Allowed source levels', 'Reason'],
            array_map(fn ($r) => [
                $r['branch']->name,
                Branch::levelLabel($r['branch']->level),
                $r['source']?->name ?? '—',
                Branch::levelLabel($r['source']?->level),
                implode(', ', array_map(fn ($l) => Branch::levelLabel($l), $r['allowed'])),
                $r['reason'],
            ], $rows),
        );
        $this->warn(count($rows) . ' branch(es) need re-pointing on Master → Branches.');

        return self::FAILURE;
    }
}
