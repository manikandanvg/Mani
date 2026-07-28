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
    protected function getStats(): array
    {
        $members = Member::count();
        $activeMembers = Member::where('status', 'active')->count();
        $activeBonds = Bond::where('status', 'active')->count();
        $bondValue = (float) Bond::where('status', 'active')->sum('value');
        $commissionBooked = (float) CommissionLedger::sum('amount');
        // Gross awaiting the commission-approval gate, across all three source tables
        // (predicates mirror CommissionApprovalService::query).
        $approvalsPending = (float) CommissionLedger::where('status', '!=', 'paid')->sum('amount')
            + (float) CbcEntry::where('status', 'pending')->sum('worth')
            + (float) ResellerCommission::where('status', '!=', 'paid')->sum('com_value');

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
                ->description('gross awaiting commission approval')
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
        ];
    }
}
