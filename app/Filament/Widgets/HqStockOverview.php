<?php

namespace App\Filament\Widgets;

use App\Models\CatalogProduct;
use App\Services\SalesService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * HQ dashboard — metal position (board 2026-11-15), gold and silver side by side:
 *
 *   HQ stock          Head Office's own holding, worked out exactly like the dealer's card
 *   Overall stock     every branch's holding added up — Head Office's own excluded
 *   Overall redeemed  metal handed out against redeemable QRs (redemption invoices)
 *   Overall requested metal in stock orders still waiting for approval (old-system orders excluded)
 *
 * stock.quantity is PIECES; grams = pieces × the item's weight (CatalogProduct::unitWeight()).
 */
class HqStockOverview extends BaseWidget
{
    protected static ?int $sort = -1;   // after the business cards, before the charts

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u !== null && ! $u->isDistributor() && ! $u->isSupport();
    }

    protected function getHeading(): ?string
    {
        return __('Gold & silver position');
    }

    /** [material => ['g' => grams, 'pcs' => pieces]] of the stock table, optionally for one branch. */
    protected function stock(?int $branchId = null, ?int $exceptBranchId = null): array
    {
        $rows = DB::table('stock')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($exceptBranchId, fn ($q) => $q->where('branch_id', '!=', $exceptBranchId))
            ->selectRaw('catalog_product_id, SUM(quantity) as qty')
            ->groupBy('catalog_product_id')->get();
        $products = CatalogProduct::whereIn('id', $rows->pluck('catalog_product_id'))->get()->keyBy('id');

        $out = ['gold' => ['g' => 0.0, 'pcs' => 0.0], 'silver' => ['g' => 0.0, 'pcs' => 0.0]];
        foreach ($rows as $r) {
            $p = $products[$r->catalog_product_id] ?? null;
            if ($p && isset($out[$p->material])) {
                $out[$p->material]['pcs'] += (float) $r->qty;
                $out[$p->material]['g'] += (float) $r->qty * $p->unitWeight();
            }
        }

        return $out;
    }

    protected function getStats(): array
    {
        $hq = $this->stock(SalesService::HQ_BRANCH_ID);
        // User 2026-11-15: "overall" = every branch EXCEPT Head Office (HQ has its own card).
        $all = $this->stock(null, SalesService::HQ_BRANCH_ID);

        $redeemed = DB::table('redemption_lines')
            ->selectRaw('material, SUM(unit_weight * quantity) as g, SUM(quantity) as pcs')
            ->whereIn('material', ['gold', 'silver'])->groupBy('material')->get()->keyBy('material');

        $requested = DB::table('branch_order_lines as l')
            ->join('branch_order_requests as o', 'o.id', '=', 'l.order_request_id')
            ->where('o.status', 'pending')->whereIn('l.material', ['gold', 'silver'])
            // user 2026-11-15: old-system pending orders are not counted
            ->where(fn ($q) => $q->whereNull('o.source')->orWhere('o.source', '!=', \App\Services\BranchOrderService::SOURCE_LEGACY))
            ->selectRaw('l.material, SUM(l.weight) as g, COUNT(DISTINCT o.id) as orders')
            ->groupBy('l.material')->get()->keyBy('material');

        $g = fn ($v) => number_format((float) $v, 3) . ' g';
        $pcs = fn ($v) => number_format((float) $v, 0) . ' ' . __('pcs');
        $orders = fn ($v) => number_format((int) $v) . ' ' . __('pending order(s)');

        return [
            Stat::make(__('HQ Gold Stock'), $g($hq['gold']['g']))->description($pcs($hq['gold']['pcs']))
                ->descriptionIcon('heroicon-m-cube')->color('warning'),
            Stat::make(__('Overall Gold Stock'), $g($all['gold']['g']))->description($pcs($all['gold']['pcs']) . ' · ' . __('all branches except HQ'))
                ->descriptionIcon('heroicon-m-building-storefront')->color('warning'),
            Stat::make(__('Overall Gold Redeemed'), $g($redeemed['gold']->g ?? 0))->description($pcs($redeemed['gold']->pcs ?? 0) . ' · ' . __('redeemed against QRs'))
                ->descriptionIcon('heroicon-m-qr-code')->color('success'),
            Stat::make(__('Overall Requested Gold'), $g($requested['gold']->g ?? 0))->description($orders($requested['gold']->orders ?? 0))
                ->descriptionIcon('heroicon-m-inbox-arrow-down')->color('danger'),

            Stat::make(__('HQ Silver Stock'), $g($hq['silver']['g']))->description($pcs($hq['silver']['pcs']))
                ->descriptionIcon('heroicon-m-cube')->color('gray'),
            Stat::make(__('Overall Silver Stock'), $g($all['silver']['g']))->description($pcs($all['silver']['pcs']) . ' · ' . __('all branches except HQ'))
                ->descriptionIcon('heroicon-m-building-storefront')->color('gray'),
            Stat::make(__('Overall Silver Redeemed'), $g($redeemed['silver']->g ?? 0))->description($pcs($redeemed['silver']->pcs ?? 0) . ' · ' . __('redeemed against QRs'))
                ->descriptionIcon('heroicon-m-qr-code')->color('success'),
            Stat::make(__('Overall Requested Silver'), $g($requested['silver']->g ?? 0))->description($orders($requested['silver']->orders ?? 0))
                ->descriptionIcon('heroicon-m-inbox-arrow-down')->color('danger'),
        ];
    }
}
