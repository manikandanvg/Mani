<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Models\SalesInvoice;
use App\Models\Stock;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Retailer (distributor) dashboard KPIs, branch-scoped — the new-app equivalent of the
 * legacy dealer dashboard cards (Total Bill / Sale value / Gold-Silver-Cash stock /
 * Bill margin). Only shown to distributors; HQ keeps the default dashboard.
 */
class RetailerStatsOverview extends BaseWidget
{
    protected static ?int $sort = -4;   // dealer's top row: bills, stock, cash, margin

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isDistributor() ?? false;
    }

    protected function getStats(): array
    {
        $branchId = auth()->user()->branch_id;
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $bills = SalesInvoice::where('branch_id', $branchId)
            ->whereBetween('date', [$start, $end])->count();

        $salesValue = (float) SalesInvoice::where('branch_id', $branchId)
            ->whereBetween('date', [$start, $end])->sum('grand_total');

        // stock.quantity = PIECES (board 2026-08-10): grams = Σ pieces × per-piece
        // weight. Cash rows hold rupees directly, so their raw sum stays correct.
        $stockGrams = fn (string $material) => (float) Stock::where('stock.branch_id', $branchId)
            ->join('catalog_products', 'catalog_products.id', '=', 'stock.catalog_product_id')
            ->where('catalog_products.material', $material)
            ->sum(\Illuminate\Support\Facades\DB::raw('stock.quantity * COALESCE(catalog_products.default_weight, 0)'));
        $stock = fn (string $material) => (float) Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', $material))
            ->sum('quantity');

        $billMargin = (float) (Branch::find($branchId)?->bill_margin ?? 0);

        return [
            Stat::make('Bills (this month)', number_format($bills))
                ->descriptionIcon('heroicon-m-document-text')->color('warning'),
            Stat::make('Sales value (month)', Money::display($salesValue))
                ->descriptionIcon('heroicon-m-banknotes')->color('warning'),
            Stat::make('Gold stock', number_format($stockGrams('gold'), 3) . ' g')
                ->description(number_format($stock('gold'), 0) . ' pcs')
                ->descriptionIcon('heroicon-m-cube')->color('warning'),
            Stat::make('Silver stock', number_format($stockGrams('silver'), 3) . ' g')
                ->description(number_format($stock('silver'), 0) . ' pcs')
                ->descriptionIcon('heroicon-m-cube')->color('gray'),
            Stat::make('Cash stock', Money::display($stock('cash')))
                ->descriptionIcon('heroicon-m-wallet')->color('success'),
            Stat::make('Bill margin earned', Money::display($billMargin))
                ->descriptionIcon('heroicon-m-receipt-percent')->color('danger'),
        ];
    }
}
