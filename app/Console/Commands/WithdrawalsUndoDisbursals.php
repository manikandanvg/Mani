<?php

namespace App\Console\Commands;

use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Illuminate\Console\Command;

/**
 * One-off for the board rule of 2026-11-03 (a branch never fronts the cash): the withdrawals
 * the branches marked "disbursed" themselves before Head Office had paid them go back to
 * pending, so they enter the request → admin paid → disburse chain. No wallet balance
 * changes — the member's money is still out and still owed.
 *
 *   php artisan withdrawals:undo-disbursals --ids=1,2,4 --dry-run
 */
class WithdrawalsUndoDisbursals extends Command
{
    protected $signature = 'withdrawals:undo-disbursals {--ids= : comma-separated wallet_withdrawals ids} {--dry-run : only list what would change}';

    protected $description = 'Put branch-marked disbursals back to pending so HQ funds them first (no balance change)';

    public function handle(WalletWithdrawalService $svc): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('ids')))));
        if (! $ids) {
            $this->error('--ids is required, e.g. --ids=1,2,4');

            return self::INVALID;
        }

        $rows = WalletWithdrawal::with(['branch', 'member'])->whereIn('id', $ids)->orderBy('id')->get();
        $missing = array_diff($ids, $rows->pluck('id')->all());
        if ($missing) {
            $this->warn('Not found: ' . implode(', ', $missing));
        }

        $this->table(['id', 'requested', 'branch', 'member', 'amount', 'status', 'disbursed at', 'action'],
            $rows->map(fn (WalletWithdrawal $w) => [
                $w->id, $w->created_at?->format('Y-m-d H:i'), $w->branch?->name, $w->member?->member_code,
                number_format((float) $w->amount, 2), $w->status, $w->disbursed_at?->format('Y-m-d H:i'),
                $w->status === 'disbursed' ? '→ pending' : 'skip (not disbursed)',
            ])->all());

        $todo = $rows->where('status', 'disbursed');
        $this->info(sprintf('%d row(s) would go back to pending, ₹%s in all.', $todo->count(), number_format((float) $todo->sum('amount'), 2)));
        if ($this->option('dry-run') || $todo->isEmpty()) {
            return self::SUCCESS;
        }
        if (! $this->option('no-interaction') && ! $this->confirm('Undo these disbursals now?', true)) {
            return self::SUCCESS;
        }

        foreach ($todo as $w) {
            $svc->undoDisbursal($w, 'disbursal undone 2026-11-03 — HQ funds the branch first');
        }
        \App\Services\Activity\ActivityLogger::log('wallet', 'disbursals_undone',
            sprintf('%d wallet withdrawal(s) put back to pending (HQ funds the branch first)', $todo->count()),
            ['details' => ['ids' => $todo->pluck('id')->all(), 'amount' => round((float) $todo->sum('amount'), 2)]]);
        $this->info(sprintf('Done — %d row(s) are pending again.', $todo->count()));

        return self::SUCCESS;
    }
}
