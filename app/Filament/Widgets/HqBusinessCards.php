<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use App\Models\RdEntry;
use App\Models\SalesInvoice;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * HQ dashboard cards (board 2026-08-10) — mirrors the legacy admin dashboard tiles:
 * today's joinings / member base / today vs month vs all-time billing and renewal
 * values. Legacy showed month and over-all as separate tiles; here each card pairs
 * "today or month" as the headline with the larger horizon underneath.
 */
class HqBusinessCards extends BaseWidget
{
    protected static ?int $sort = -3;   // top of the HQ dashboard, above Business Overview

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->isDistributor() && ! $u->isSupport();
    }

    protected function getStats(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $newbieToday = Member::whereDate('joined_on', $today)->count();
        $totalMembers = Member::count();

        $salesToday = (float) SalesInvoice::whereDate('date', $today)->sum('grand_total');
        $salesMonth = (float) SalesInvoice::whereDate('date', '>=', $monthStart)->sum('grand_total');
        $salesAll = (float) SalesInvoice::sum('grand_total');

        $renewalToday = (float) RdEntry::whereDate('paid_on', $today)->sum('value');
        $renewalMonth = (float) RdEntry::whereDate('paid_on', '>=', $monthStart)->sum('value');
        $renewalAll = (float) RdEntry::sum('value');

        return [
            Stat::make(__("Today's Newbie"), Number::format($newbieToday))
                ->description(__('New joining members'))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('warning'),

            Stat::make(__('Total Available'), Number::format($totalMembers))
                ->description(__('All members'))
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make(__('Today Sales'), Money::display($salesToday))
                ->description(__('This month: :v', ['v' => Money::display($salesMonth)]))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make(__('Today Renewal'), Money::display($renewalToday))
                ->description(__('This month: :v', ['v' => Money::display($renewalMonth)]))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            Stat::make(__('Invoice Value (Month)'), Money::display($salesMonth))
                ->description(__('Over all: :v', ['v' => Money::display($salesAll)]))
                ->descriptionIcon('heroicon-m-document-chart-bar')
                ->color('danger'),

            Stat::make(__('Renewal Value (Month)'), Money::display($renewalMonth))
                ->description(__('Over all: :v', ['v' => Money::display($renewalAll)]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('danger'),
        ];
    }
}
