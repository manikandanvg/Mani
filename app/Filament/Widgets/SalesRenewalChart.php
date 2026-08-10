<?php

namespace App\Filament\Widgets;

use App\Models\RdEntry;
use App\Models\SalesInvoice;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

/**
 * Sales value vs RD / Gold-Saving renewal value, plotted month by month.
 *
 * Shared by BOTH back-office logins:
 *   - Head office (super_admin) sees the figure aggregated across ALL branches.
 *   - A distributor sees only their own branch (branch_id scoped), the same way
 *     the rest of their panel is scoped.
 *
 * Interactive: the period selector (3 / 6 / 12 months) lets either role re-frame
 * the trend without leaving the dashboard. Defaults to the last 6 months.
 */
class SalesRenewalChart extends ChartWidget
{
    protected static ?string $heading = 'Sales & RD renewal trend';

    protected static ?int $sort = 10;   // board 2026-08-09: all stat widgets first, trend chart last

    protected int|string|array $columnSpan = 'full';

    /** Default period; bound to the <select> rendered by getFilters(). */
    public ?string $filter = '6';

    /** Only staff who can act on this data: head office and branch distributors. */
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isSuperAdmin() ?? false) || ($user?->isDistributor() ?? false);
    }

    protected function getFilters(): ?array
    {
        return [
            '3' => 'Last 3 months',
            '6' => 'Last 6 months',
            '12' => 'Last 12 months',
        ];
    }

    public function getDescription(): ?string
    {
        $symbol = Money::currency(Money::current())?->symbol ?? '';
        $scope = auth()->user()?->isSuperAdmin() ? 'All branches' : 'Your branch';

        return trim("{$scope} · {$symbol} per month");
    }

    protected function getData(): array
    {
        $months = (int) ($this->filter ?? 6);
        $months = in_array($months, [3, 6, 12], true) ? $months : 6;

        // Distributors are pinned to their own branch; head office sees everything.
        $branchId = auth()->user()?->isDistributor() ? auth()->user()->branch_id : null;

        // Amounts are stored in base currency; show them in the viewer's chosen currency.
        $currency = Money::current();
        $symbol = Money::currency($currency)?->symbol ?? '';

        $labels = [];
        $sales = [];
        $renewal = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $range = [$monthStart->toDateString(), $monthEnd->toDateString()];
            $labels[] = $monthStart->format('M Y');

            $sales[] = Money::convert((float) SalesInvoice::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('date', $range)
                ->sum('grand_total'), $currency);

            $renewal[] = Money::convert((float) RdEntry::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('paid_on', $range)
                ->sum('value'), $currency);
        }

        return [
            'datasets' => [
                ['label' => trim("Sales value ({$symbol})"), 'data' => $sales, 'backgroundColor' => '#ab222f', 'borderColor' => '#ab222f'],
                ['label' => trim("RD renewal ({$symbol})"), 'data' => $renewal, 'backgroundColor' => '#e6ad46', 'borderColor' => '#e6ad46'],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
