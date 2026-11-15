<?php

namespace App\Filament\Widgets;

use App\Models\WalletWithdrawal;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Distributor dashboard — the L-BOX payout road in five cards (user 2026-11-15), each with
 * the amount and the number of transactions of THIS branch:
 *
 *   1. LBOX Collected      payments members made by scanning the branch QR (not cancelled)
 *   2. Admin Request       sent to Head Office, awaiting its approval          (status requested)
 *   3. Admin Approved      approved / funded by Head Office — to hand over, or already handed over
 *   4. Pending Disbursed   approved by Head Office, not yet handed over        (status admin_paid)
 *   5. Disbursed           handed over at this branch                          (status disbursed)
 *
 * Chain on wallet_withdrawals.status (board 2026-11-03): pending → requested → admin_paid →
 * disbursed; a branch never fronts the cash.
 */
class DealerPayoutFlow extends BaseWidget
{
    protected static ?int $sort = 5;   // above the Sales & Gold Saving renewal trend chart (sort 10)

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    /** Two rows: three cards, then two (Filament lays stats out in 1–4 columns; 5 fell back to one per row). */
    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->isDistributor() && $u->branch_id);
    }

    protected function getHeading(): ?string
    {
        return __('L-BOX payments — collection to disbursal');
    }

    protected function getStats(): array
    {
        $branchId = (int) auth()->user()->branch_id;

        $by = DB::table('wallet_withdrawals')->where('branch_id', $branchId)
            ->selectRaw('status, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS a')
            ->groupBy('status')->get()->keyBy('status');
        $sum = fn (array $statuses) => [
            (int) collect($statuses)->sum(fn ($s) => (int) ($by[$s]->c ?? 0)),
            (float) collect($statuses)->sum(fn ($s) => (float) ($by[$s]->a ?? 0)),
        ];

        // 1. scans at the branch QR / L-BOX only (a dealer's own panel withdrawal is not a collection)
        $lbox = DB::table('wallet_withdrawals')->where('branch_id', $branchId)->whereNotNull('device_id')
            ->where('status', '!=', 'cancelled')
            ->selectRaw("COUNT(*) AS c, COALESCE(SUM(amount), 0) AS a, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS fresh,"
                . " SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS fresh_c")->first();

        [$reqC, $reqA] = $sum(['requested']);
        [$okC, $okA] = $sum(['admin_paid']);
        [$doneC, $doneA] = $sum(['disbursed']);
        // approved by Head Office = everything it funded through a payout request, handed over or not
        $approved = DB::table('wallet_withdrawals as w')->join('branch_payout_requests as r', 'r.id', '=', 'w.payout_request_id')
            ->where('w.branch_id', $branchId)->where('r.status', 'paid')->where('w.status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(w.amount), 0) AS a')->first();

        $txn = fn (int $n) => number_format($n) . ' ' . ($n === 1 ? __('transaction') : __('transactions'));

        return [
            Stat::make(__('LBOX Collected'), Money::display((float) ($lbox->a ?? 0)))
                ->description($txn((int) ($lbox->c ?? 0)) . ((int) ($lbox->fresh_c ?? 0) > 0
                    ? ' · ' . Money::display((float) $lbox->fresh) . ' ' . __('not yet requested') : ''))
                ->descriptionIcon('heroicon-m-qr-code')->color('info'),
            Stat::make(__('Admin Request'), Money::display($reqA))
                ->description($txn($reqC) . ' · ' . __('awaiting Head Office approval'))
                ->descriptionIcon('heroicon-m-clock')->color($reqC > 0 ? 'warning' : 'gray'),
            Stat::make(__('Admin Approved'), Money::display((float) ($approved->a ?? 0)))
                ->description($txn((int) ($approved->c ?? 0)) . ' · ' . __('approved by Head Office'))
                ->descriptionIcon('heroicon-m-check-badge')->color('success'),
            Stat::make(__('Pending Disbursed'), Money::display($okA))
                ->description($txn($okC) . ' · ' . __('approved, to be handed over'))
                ->descriptionIcon('heroicon-m-hand-raised')->color($okC > 0 ? 'danger' : 'gray'),
            Stat::make(__('Disbursed'), Money::display($doneA))
                ->description($txn($doneC) . ' · ' . __('handed over at this branch'))
                ->descriptionIcon('heroicon-m-banknotes')->color('success'),
        ];
    }
}
