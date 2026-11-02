<?php

namespace App\Filament\Widgets;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\ResellerCommission;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class BusinessOverview extends BaseWidget
{
    protected static ?int $sort = -2;

    /**
     * HQ-wide numbers — head-office eyes only. A distributor's dashboard shows the
     * branch-scoped RetailerStatsOverview instead (board fix 2026-08-09); support
     * staff get their own SupportOverview.
     */
    public static function canView(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->isDistributor() && ! $u->isSupport();
    }

    protected function getStats(): array
    {
        $members = Member::count();
        $activeMembers = Member::where('status', 'active')->count();
        $activeBonds = Bond::where('status', 'active')->count();
        $bondValue = (float) Bond::where('status', 'active')->sum('value');
        $commissionBooked = (float) CommissionLedger::sum('amount');
        // Gross awaiting the commission-approval gate, across the three source tables (predicates
        // mirror CommissionApprovalService::query). User 2026-11-02: the figure showed ₹239 cr
        // because it also summed every FUTURE-dated CBC coupon (one per bond per month up to
        // 2028, ₹186 cr) and the legacy dealer-margin HISTORY (₹4.78 cr) — neither is approvable.
        $today = now()->toDateString();
        $icPending = (float) CommissionLedger::where('type', 'IC')->where('status', '!=', 'paid')->sum('amount');
        $gapPending = (float) CommissionLedger::where('type', 'GAP')->where('status', '!=', 'paid')->sum('amount');
        // (user 2026-11-02: future-dated coupons are neither counted nor mentioned here)
        $cbcDue = (float) CbcEntry::where('status', 'pending')->whereDate('cbc_date', '<=', $today)->sum('worth');
        $marginsPending = (float) ResellerCommission::where('status', '!=', 'paid')->where('source', '!=', ResellerCommission::SOURCE_LEGACY)->sum('com_value');
        $approvalsPending = $icPending + $gapPending + $cbcDue + $marginsPending;
        $approvalsNote = 'IC ' . Money::display($icPending) . ' · GAP ' . Money::display($gapPending) . ' · CBC due ' . Money::display($cbcDue)
            . ' · dealer margins ' . Money::display($marginsPending);

        return [
            Stat::make('Distributors', Number::format($members))
                ->description("{$activeMembers} active")
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Active Bonds', Number::format($activeBonds))
                ->description(Money::display($bondValue) . ' business volume')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Commission Booked', Money::display($commissionBooked))
                ->description('IC + GAP + CBC ledger')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning'),

            Stat::make('Approvals Pending', Money::display($approvalsPending))
                ->description($approvalsNote)
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
        ];
    }
}
