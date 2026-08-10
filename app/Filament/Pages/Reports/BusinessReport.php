<?php

namespace App\Filament\Pages\Reports;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\RdEntry;
use App\Models\RedemptionInvoice;
use App\Models\SalesInvoice;
use App\Models\StockReturn;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Facades\DB;

/** Business Report — sales / purchases / redemptions / RD / returns by period & branch. */
class BusinessReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Business Report';

    protected static ?string $title = 'Business Report';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\DatePicker::make('from')->required()->native(false),
                Forms\Components\DatePicker::make('to')->required()->native(false),
                Forms\Components\Select::make('branch_id')->label('Branch')
                    ->options(static::branchOptions())
                    ->placeholder(__('All branches'))
                    ->disabled(static::dealerBranchId() !== null)
                    ->default(static::dealerBranchId())
                    ->native(false),
            ]),
        ])->statePath('data');
    }

    public function run(): void
    {
        $d = $this->form->getState();
        [$from, $to] = [$d['from'], $d['to']];
        $branch = $this->branchFilter();
        $this->sections = [];
        $this->ran = true;

        $scope = fn ($query, string $dateCol, string $branchCol = 'branch_id') => $query
            ->whereDate($dateCol, '>=', $from)->whereDate($dateCol, '<=', $to)
            ->when($branch, fn ($q) => $q->where($branchCol, $branch));

        $sales = $scope(SalesInvoice::query(), 'date')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(grand_total),0) t')->first();
        $purchases = $scope(Purchase::query(), 'purchase_date')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(grand_total),0) t')->first();
        $redemptions = $scope(RedemptionInvoice::query(), 'invoice_date')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(grand_total),0) t')->first();
        $rd = $scope(RdEntry::query(), 'paid_on')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(value),0) t')->first();
        $returns = $scope(StockReturn::where('status', 'approved'), 'created_at')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total_amount),0) t')->first();
        $orders = Order::whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->where('payment_status', 'paid')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();

        $this->sections[] = ['heading' => __('Summary (:from – :to)', ['from' => $this->dmy($from), 'to' => $this->dmy($to)]), 'kv' => [
            __('Sales invoices') => "{$sales->c} / " . $this->money($sales->t),
            __('Purchases') => "{$purchases->c} / " . $this->money($purchases->t),
            __('Redemption invoices') => "{$redemptions->c} / " . $this->money($redemptions->t),
            __('RD collected') => "{$rd->c} / " . $this->money($rd->t),
            __('Stock returns (approved)') => "{$returns->c} / " . $this->money($returns->t),
            __('Online orders (paid)') => "{$orders->c} / " . $this->money($orders->t),
        ]];

        if (! $branch) {
            $per = fn ($query, string $dateCol, string $sumCol) => $query
                ->whereDate($dateCol, '>=', $from)->whereDate($dateCol, '<=', $to)
                ->groupBy('branch_id')
                ->pluck(DB::raw("COALESCE(SUM({$sumCol}),0)"), 'branch_id');

            $salesBy = $per(SalesInvoice::query(), 'date', 'grand_total');
            $purchBy = $per(Purchase::query(), 'purchase_date', 'grand_total');
            $redemBy = $per(RedemptionInvoice::query(), 'invoice_date', 'grand_total');
            $rdBy = $per(RdEntry::query(), 'paid_on', 'value');

            $rows = Branch::orderBy('name')->get()->map(fn ($b) => [
                $b->name,
                $this->money($salesBy[$b->id] ?? 0),
                $this->money($purchBy[$b->id] ?? 0),
                $this->money($redemBy[$b->id] ?? 0),
                $this->money($rdBy[$b->id] ?? 0),
            ])->all();

            $this->sections[] = ['heading' => __('Per branch'),
                'columns' => [__('Branch'), __('Sales'), __('Purchases'), __('Redemptions'), __('RD collected')],
                'rows' => $rows];
        }
    }
}
