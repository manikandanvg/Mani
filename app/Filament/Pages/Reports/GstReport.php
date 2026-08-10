<?php

namespace App\Filament\Pages\Reports;

use App\Models\Purchase;
use App\Models\RedemptionInvoice;
use App\Models\SalesInvoice;
use Filament\Forms;
use Filament\Forms\Form;

/**
 * GST Report — invoice-wise output tax (sales + redemption, CGST/SGST split per
 * Branch::taxOn) and input-side purchase GST, ready for filing exports.
 */
class GstReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'GST Report';

    protected static ?string $title = 'GST Report';

    private const LIMIT = 1000;

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

        $sales = SalesInvoice::with('branch')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('date')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => __('Output — sales invoices'),
            'kv' => [
                __('Invoices') => (string) $sales->count(),
                __('Net (taxable)') => $this->money($sales->sum('net_total')),
                __('CGST') => $this->money($sales->sum('cgst')),
                __('SGST') => $this->money($sales->sum('sgst')),
                __('Grand total') => $this->money($sales->sum('grand_total')),
            ],
            'columns' => [__('Invoice #'), __('Date'), __('Branch'), __('Customer'), __('Regime'), __('Taxable'), __('CGST'), __('SGST'), __('Tax total'), __('Grand total')],
            'rows' => $sales->map(fn ($s) => [
                $s->invoice_no, $this->dmy($s->date), $s->branch?->name, $s->customer_name,
                strtoupper((string) $s->tax_regime), $this->money($s->net_total), $this->money($s->cgst),
                $this->money($s->sgst), $this->money($s->tax_total), $this->money($s->grand_total),
            ])->all()];

        $redeem = RedemptionInvoice::with('branch')
            ->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('invoice_date')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => __('Output — redemption invoices'),
            'kv' => [
                __('Invoices') => (string) $redeem->count(),
                __('Taxable') => $this->money($redeem->sum('taxable_total')),
                __('CGST') => $this->money($redeem->sum('cgst')),
                __('SGST') => $this->money($redeem->sum('sgst')),
                __('Grand total') => $this->money($redeem->sum('grand_total')),
            ],
            'columns' => [__('Invoice #'), __('Date'), __('Branch'), __('Buyer GST'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total')],
            'rows' => $redeem->map(fn ($i) => [
                $i->invoice_no, $this->dmy($i->invoice_date), $i->branch?->name, $i->buyer_gst,
                $this->money($i->taxable_total), $this->money($i->cgst), $this->money($i->sgst), $this->money($i->grand_total),
            ])->all()];

        $purchases = Purchase::with(['vendor', 'branch'])
            ->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('purchase_date')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => __('Input — purchases'),
            'kv' => [
                __('Purchases') => (string) $purchases->count(),
                __('Gross') => $this->money($purchases->sum('gross_total')),
                __('GST') => $this->money($purchases->sum('gst_total')),
                __('Grand total') => $this->money($purchases->sum('grand_total')),
            ],
            'columns' => [__('Ref #'), __('Date'), __('Branch'), __('Vendor'), __('Gross'), __('GST'), __('Grand total')],
            'rows' => $purchases->map(fn ($p) => [
                $p->ref_no, $this->dmy($p->purchase_date), $p->branch?->name, $p->vendor?->name,
                $this->money($p->gross_total), $this->money($p->gst_total), $this->money($p->grand_total),
            ])->all()];
    }
}
