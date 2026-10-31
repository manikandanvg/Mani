<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\Activity\ActivityLogger;
use App\Services\Geo\HierarchyMapper;
use Illuminate\Console\Command;

/**
 * Board 2026-10-31: map distributors to their serving branch BY PINCODE — the nearest
 * taluka / reseller / sub-dealer / wholesaler / area distributor in the same pincode,
 * taluka, district or state.
 *
 *   php artisan members:auto-map                 # report for distributors WITHOUT a branch
 *   php artisan members:auto-map --apply         # write those
 *   php artisan members:auto-map --all --apply   # re-map every distributor (overrides the billing branch!)
 */
class MembersAutoMap extends Command
{
    protected $signature = 'members:auto-map
        {--apply : Write the proposed branch onto each distributor (default: report only)}
        {--all : Include distributors that already have a branch (default: unmapped only)}
        {--limit=0 : Stop after N distributors (0 = no limit)}';

    protected $description = 'Map distributors to a branch by pincode locality';

    public function handle(HierarchyMapper $mapper): int
    {
        $q = Member::query()->where('status', 'active')->whereNotNull('pincode')->where('pincode', '!=', '')
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('branch_id'))
            ->orderBy('id');
        if ((int) $this->option('limit') > 0) {
            $q->limit((int) $this->option('limit'));
        }

        [$mapped, $unmatched, $same] = [0, 0, 0];
        $sample = [];
        foreach ($q->cursor() as $m) {
            $r = $mapper->mapMember($m);
            if (! $r['branch']) {
                $unmatched++;
                continue;
            }
            if ((int) $r['branch']->id === (int) $m->branch_id) {
                $same++;
                continue;
            }
            $mapped++;
            if (count($sample) < 25) {
                $sample[] = [$m->member_code, $m->pincode, $m->branch_id ?: '—', $r['branch']->name, $r['reason']];
            }
            if ($this->option('apply')) {
                $from = $m->branch_id;
                $m->update(['branch_id' => $r['branch']->id]);
                ActivityLogger::log('network', 'auto_mapped', "Distributor {$m->member_code} mapped to {$r['branch']->name} by pincode ({$r['reason']})", [
                    'subject' => $m, 'branch' => $r['branch']->id, 'details' => ['from' => $from, 'to' => $r['branch']->id, 'reason' => $r['reason']],
                ]);
            }
        }
        if ($sample) {
            $this->table(['Distributor', 'Pincode', 'Current branch', 'Proposed branch', 'Why'], $sample);
            if ($mapped > count($sample)) {
                $this->line('… and ' . ($mapped - count($sample)) . ' more.');
            }
        }
        $this->info(($this->option('apply') ? 'Mapped' : 'Would map') . ": {$mapped} · already right: {$same} · no branch in their state / unknown pincode: {$unmatched}");
        if (! $this->option('apply') && $mapped) {
            $this->line('Re-run with --apply to write them.');
        }

        return self::SUCCESS;
    }
}
