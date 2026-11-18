<?php

namespace App\Console\Commands;

use App\Services\RdCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Complaint 2026-11-18: Gold Saving renewals saved with the "Cash stock" field left empty
 * reduced no cash stock (live audit of 18 Nov: 171 renewals since 3 Nov). This deducts each of
 * them from its branch's cash stock now, with a stock movement dated the day of the renewal
 * and carrying the renewal's id — so a second run finds nothing left to do.
 *
 *   php artisan stock:fix-renewal-deductions            dry run (per-branch totals)
 *   php artisan stock:fix-renewal-deductions --apply    writes the deductions
 */
class StockFixRenewalDeductions extends Command
{
    protected $signature = 'stock:fix-renewal-deductions {--from=2026-11-01 : first date to check (new system go-live)} {--apply : write the deductions (default = dry run)}';

    protected $description = 'Deduct from the branch cash stock the Gold Saving renewals that were saved without a deduction';

    public function handle(RdCollectionService $service): int
    {
        $apply = (bool) $this->option('apply');
        $missing = RdCollectionService::undeductedEntries((string) $this->option('from'));
        $branches = DB::table('branches')->pluck('name', 'id');

        $rows = [];
        foreach ($missing->groupBy('branch_id') as $branchId => $entries) {
            $before = (float) (RdCollectionService::branchCashStock((int) $branchId, false)?->quantity ?? 0);
            $total = round((float) $entries->sum('value'), 2);
            if ($apply) {
                DB::transaction(fn () => $entries->each(fn ($e) => $service->deductForEntry($e)));
            }
            $rows[] = [$branchId, $branches[$branchId] ?? '—', $entries->count(), number_format($total, 2),
                number_format($before, 2), number_format($before - $total, 2)];
        }

        $this->info(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $missing->count() . ' renewal(s) without a cash deduction, ₹'
            . number_format((float) $missing->sum('value'), 2));
        $this->table(['Branch id', 'Branch', 'Renewals', 'To deduct ₹', 'Cash stock now ₹', 'Cash stock after ₹'], $rows);
        if (! $apply && $missing->isNotEmpty()) {
            $this->line('Run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
