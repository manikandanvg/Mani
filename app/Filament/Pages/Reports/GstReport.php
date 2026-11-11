<?php

namespace App\Filament\Pages\Reports;

use App\Models\Purchase;
use App\Support\Gst\GstAuditorReport;
use App\Support\Gst\GstAuditorWorkbook;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Carbon;

/**
 * GST Report — the auditor's two registers built from REDEEMED INVOICES only (board,
 * 2026-11-11): B2C = redemptions at branches under the filing GSTIN, B2B = redemptions at
 * dealer branches billed to the dealer's GSTIN. Each carries a running INV NO that
 * continues the auditor's sequence and the redemption invoice no as REF NO, plus the
 * GSTR-1 offline-tool workbook derived from the same rows and the input-side purchase GST.
 * Source "legacy" reads the Lord DB for pre-go-live months; "new" reads redemption_invoices.
 */
class GstReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'GST Report';

    protected static ?string $title = 'GST Report';

    private const SCREEN_ROWS = 500;

    public function mount(): void
    {
        $from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $to = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->form->fill([
            'from' => $from,
            'to' => $to,
            'source' => GstAuditorReport::SOURCE_NEW,
            'b2c_start' => GstAuditorReport::nextNumber('b2c', $from, $to),
            'b2b_start' => GstAuditorReport::nextNumber('b2b', $from, $to),
        ]);
    }

    public function form(Form $form): Form
    {
        $refresh = function (Forms\Get $get, Forms\Set $set) {
            if ($get('from') && $get('to')) {
                $set('b2c_start', GstAuditorReport::nextNumber('b2c', $get('from'), $get('to')));
                $set('b2b_start', GstAuditorReport::nextNumber('b2b', $get('from'), $get('to')));
            }
        };

        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\DatePicker::make('from')->required()->native(false)->live()->afterStateUpdated($refresh),
                Forms\Components\DatePicker::make('to')->required()->native(false)->live()->afterStateUpdated($refresh),
                Forms\Components\Select::make('source')->label(__('Data source'))
                    ->options(GstAuditorReport::legacyAvailable() ? GstAuditorReport::sourceOptions() : [GstAuditorReport::SOURCE_NEW => __('New system')])
                    ->required()->native(false)->selectablePlaceholder(false)
                    ->helperText(__('Months before go-live (up to Oct 2026) live in the Lord DB.')),
                Forms\Components\Select::make('branch_id')->label('Branch')
                    ->options(static::branchOptions())
                    ->placeholder(__('All branches under the filing GSTIN'))
                    ->disabled(static::dealerBranchId() !== null)
                    ->default(static::dealerBranchId())
                    ->native(false),
                Forms\Components\TextInput::make('b2c_start')->label(__('B2C first INV NO'))->numeric()->required()
                    ->helperText(__('Continues the auditor sequence; remembered after each export.')),
                Forms\Components\TextInput::make('b2b_start')->label(__('B2B first INV NO'))->numeric()->required(),
            ]),
        ])->statePath('data');
    }

    protected function report(): GstAuditorReport
    {
        $d = $this->form->getState();

        return new GstAuditorReport($d['source'] ?? GstAuditorReport::SOURCE_NEW, $d['from'], $d['to'], $this->branchFilter());
    }

    public function run(): void
    {
        $d = $this->form->getState();
        [$from, $to] = [$d['from'], $d['to']];
        $branch = $this->branchFilter();
        $this->sections = [];
        $this->ran = true;

        $report = $this->report();
        $b2c = GstAuditorReport::number($report->b2cRows(), (int) $d['b2c_start']);
        $b2b = GstAuditorReport::number($report->b2bRows(), (int) $d['b2b_start']);

        foreach ([[$b2c, __('B2C — redemptions under GSTIN :g (consumer invoices)', ['g' => $report->gstin]), GstAuditorReport::B2C_COLUMNS],
            [$b2b, __('B2B — redemptions at dealer branches (billed to the dealer GSTIN)'), GstAuditorReport::B2B_COLUMNS]] as [$rows, $heading, $columns]) {
            $t = GstAuditorReport::totals($rows);
            $kv = [
                __('Rows') => (string) $t['rows'],
                __('INV NO range') => $t['rows'] ? $rows[0]['INV NO'].' – '.end($rows)['INV NO'] : '—',
                __('Billing value') => $this->money($t['billing']),
                __('Taxable') => $this->money($t['taxable']),
                __('GST 3% (CGST + SGST)') => $this->money($t['gst']),
            ];
            if ($t['rows'] > self::SCREEN_ROWS) {
                $kv[__('On screen')] = __('first :n rows — the Excel exports carry every row', ['n' => self::SCREEN_ROWS]);
            }
            $this->sections[] = ['heading' => $heading, 'kv' => $kv, 'columns' => $columns,
                'rows' => array_map(fn ($r) => array_map(fn ($c) => $r[$c] instanceof \DateTimeInterface ? $this->dmy($r[$c]) : $r[$c], $columns),
                    array_slice($rows, 0, self::SCREEN_ROWS))];
        }
        if ($report->notes) {
            $this->sections[] = ['heading' => __('Notes'), 'columns' => [__('Note')], 'rows' => array_map(fn ($n) => [$n], $report->notes)];
        }

        $purchases = Purchase::with(['vendor', 'branch'])
            ->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('purchase_date')->limit(1000)->get();
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

    /** "GSTR REPORT B2C <month>.xlsx" — the auditor's consumer register. */
    public function exportB2c()
    {
        return $this->exportRegister('b2c');
    }

    /** "GSTR REPORT B2B <month>.xlsx" — the auditor's dealer register. */
    public function exportB2b()
    {
        return $this->exportRegister('b2b');
    }

    protected function exportRegister(string $register)
    {
        $d = $this->form->getState();
        $report = $this->report();
        $start = (int) $d[$register.'_start'];
        $rows = GstAuditorReport::number($register === 'b2b' ? $report->b2bRows() : $report->b2cRows(), $start);
        GstAuditorReport::recordRun($register, $d['from'], $d['to'], $start, count($rows));
        $path = tempnam(sys_get_temp_dir(), 'gst');
        GstAuditorWorkbook::register($register, $rows, $path);

        return response()->download($path, 'GSTR REPORT '.strtoupper($register).' '.$this->periodLabel().'.xlsx')->deleteFileAfterSend();
    }

    /** GSTR-1 offline-tool workbook (B2B / B2CS / HSN) built from the same two registers. */
    public function exportGstr1()
    {
        $d = $this->form->getState();
        $report = $this->report();
        $b2c = GstAuditorReport::number($report->b2cRows(), (int) $d['b2c_start']);
        $b2b = GstAuditorReport::number($report->b2bRows(), (int) $d['b2b_start']);
        $path = tempnam(sys_get_temp_dir(), 'gstr1');
        GstAuditorWorkbook::gstr1($b2c, $b2b, $path);

        return response()->download($path, 'GSTR1 '.$this->periodLabel().'.xlsx')->deleteFileAfterSend();
    }

    protected function periodLabel(): string
    {
        $d = $this->form->getState();
        $from = Carbon::parse($d['from']);
        $to = Carbon::parse($d['to']);

        return $from->isSameMonth($to) ? $from->format('M Y') : $from->format('d M Y').' to '.$to->format('d M Y');
    }
}
