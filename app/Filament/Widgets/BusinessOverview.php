<?php

namespace App\Filament\Widgets;

use App\Models\Bond;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\PayoutStatement;
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
        $payoutPending = (float) PayoutStatement::where('status', 'pending')->sum('grand_total');

        return [
            Stat::make('Members', Number::format($members))
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

            Stat::make('Payouts Pending', Money::display($payoutPending))
                ->description('net after TDS + service')
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
        ];
    }
}
