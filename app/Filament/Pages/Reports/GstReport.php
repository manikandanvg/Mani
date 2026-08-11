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

        // Consolidations (board 2026-08-11): when running across ALL branches, add
        // per-branch and per-LEVEL (taluk/district/zonal…) output-tax summaries.
        if (! $branch) {
            $branches = \App\Models\Branch::pluck('name', 'id');
            $levels = \App\Models\Branch::pluck('level', 'id');
            $combined = $sales->map(fn ($s) => ['b' => $s->branch_id, 'taxable' => (float) $s->net_total, 'cgst' => (float) $s->cgst, 'sgst' => (float) $s->sgst, 'grand' => (float) $s->grand_total])
                ->concat($redeem->map(fn ($i) => ['b' => $i->branch_id, 'taxable' => (float) $i->taxable_total, 'cgst' => (float) $i->cgst, 'sgst' => (float) $i->sgst, 'grand' => (float) $i->grand_total]));

            $this->sections[] = ['heading' => __('Consolidation — per branch (output tax)'),
                'columns' => [__('Branch'), __('Level'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total')],
                'rows' => $combined->groupBy('b')->map(fn ($rows, $bid) => [
                    $branches[$bid] ?? "#{$bid}",
                    ucfirst((string) ($levels[$bid] ?? '—')),
                    $this->money($rows->sum('taxable')), $this->money($rows->sum('cgst')),
                    $this->money($rows->sum('sgst')), $this->money($rows->sum('grand')),
                ])->values()->all()];

            $this->sections[] = ['heading' => __('Consolidation — per level (zonal / district / taluk …)'),
                'columns' => [__('Level'), __('Branches'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total')],
                'rows' => $combined->groupBy(fn ($r) => $levels[$r['b']] ?? 'HQ / unlevelled')
                    ->map(fn ($rows, $level) => [
                        ucfirst((string) $level),
                        (string) $rows->pluck('b')->unique()->count(),
                        $this->money($rows->sum('taxable')), $this->money($rows->sum('cgst')),
                        $this->money($rows->sum('sgst')), $this->money($rows->sum('grand')),
                    ])->values()->all()];
        }

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

    /**
     * GSTR-1-format Excel (board 2026-08-11): three sheets in the govt offline-tool
     * shape — B2B (invoice-wise, buyer GSTIN present), B2CS (rate-wise consolidated
     * consumer sales), HSN (item summary from invoice lines). Covers sales +
     * redemption output invoices for the selected period/branch.
     */
    public function exportGstr1()
    {
        $d = $this->form->getState();
        [$from, $to] = [$d['from'], $d['to']];
        $branch = $this->branchFilter();
        $pos = '33-Tamil Nadu';

        $sales = SalesInvoice::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get();
        $redeem = RedemptionInvoice::whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get();

        $docs = $sales->map(fn ($s) => [
            'gstin' => $s->buyer_gst, 'name' => $s->customer_name, 'no' => $s->invoice_no,
            'date' => $s->date, 'value' => (float) $s->grand_total, 'taxable' => (float) $s->net_total,
            'cgst' => (float) $s->cgst, 'sgst' => (float) $s->sgst,
        ])->concat($redeem->map(fn ($i) => [
            'gstin' => $i->buyer_gst, 'name' => $i->member?->name ?? $i->referrer_name, 'no' => $i->invoice_no,
            'date' => $i->invoice_date, 'value' => (float) $i->grand_total, 'taxable' => (float) $i->taxable_total,
            'cgst' => (float) $i->cgst, 'sgst' => (float) $i->sgst,
        ]));

        $rate = fn ($r) => $r['taxable'] > 0 ? round(($r['cgst'] + $r['sgst']) / $r['taxable'] * 100, 2) : 0.0;

        $path = tempnam(sys_get_temp_dir(), 'gstr1');
        $writer = new \OpenSpout\Writer\XLSX\Writer;
        $writer->openToFile($path);
        $row = fn (array $v) => \OpenSpout\Common\Entity\Row::fromValues($v);

        // Sheet 1: B2B
        $writer->getCurrentSheet()->setName('B2B');
        $writer->addRow($row(['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Invoice Type', 'Rate', 'Taxable Value', 'Cess Amount']));
        foreach ($docs->filter(fn ($r) => filled($r['gstin'])) as $r) {
            $writer->addRow($row([
                $r['gstin'], (string) $r['name'], $r['no'],
                \Illuminate\Support\Carbon::parse($r['date'])->format('d-M-y'),
                round($r['value'], 2), $pos, 'N', 'Regular', $rate($r), round($r['taxable'], 2), 0,
            ]));
        }

        // Sheet 2: B2CS — rate-wise consolidated consumer sales
        $writer->addNewSheetAndMakeItCurrent()->setName('B2CS');
        $writer->addRow($row(['Type', 'Place Of Supply', 'Rate', 'Taxable Value', 'Cess Amount']));
        foreach ($docs->filter(fn ($r) => blank($r['gstin']))->groupBy($rate) as $ratePct => $rows) {
            $writer->addRow($row(['OE', $pos, (float) $ratePct, round($rows->sum('taxable'), 2), 0]));
        }

        // Sheet 3: HSN summary from invoice lines
        $writer->addNewSheetAndMakeItCurrent()->setName('HSN');
        $writer->addRow($row(['HSN', 'Description', 'UQC', 'Total Quantity', 'Taxable Value', 'Integrated Tax', 'Central Tax', 'State/UT Tax', 'Cess']));
        $products = \App\Models\CatalogProduct::pluck('hsn_code', 'id');
        $hsn = [];
        foreach (\App\Models\SaleLine::whereHas('invoice', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($branch, fn ($qq) => $qq->where('branch_id', $branch)))->get() as $l) {
            $code = $products[$l->catalog_product_id] ?? '7113';
            $hsn[$code]['qty'] = ($hsn[$code]['qty'] ?? 0) + (float) $l->qty;
            $hsn[$code]['taxable'] = ($hsn[$code]['taxable'] ?? 0) + (float) $l->line_total;
        }
        foreach (\App\Models\RedemptionLine::whereHas('invoice', fn ($q) => $q->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->when($branch, fn ($qq) => $qq->where('branch_id', $branch)))->get() as $l) {
            $code = $l->hsn_code ?: ($products[$l->catalog_product_id] ?? '7113');
            $hsn[$code]['qty'] = ($hsn[$code]['qty'] ?? 0) + (float) $l->quantity;
            $hsn[$code]['taxable'] = ($hsn[$code]['taxable'] ?? 0) + (float) $l->line_total;
        }
        foreach ($hsn as $code => $t) {
            $tax = round($t['taxable'] * 0.03, 2);   // metal rate 3% split half/half
            $writer->addRow($row([(string) $code, 'Jewellery / precious metal articles', 'GRM', round($t['qty'], 3), round($t['taxable'], 2), 0, round($tax / 2, 2), round($tax / 2, 2), 0]));
        }
        $writer->close();

        return response()->download($path, 'gstr1-' . $from . '-to-' . $to . '.xlsx')->deleteFileAfterSend();
    }
}
