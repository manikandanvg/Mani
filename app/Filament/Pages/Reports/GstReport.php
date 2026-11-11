<?php

namespace App\Filament\Pages\Reports;

use App\Models\Purchase;
use App\Support\Gst\GstAuditorReport;
use App\Support\Gst\GstAuditorWorkbook;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Carbon;

/**
 * GST Report — built from REDEEMED INVOICES only (board, 2026-11-11).
 *
 * HQ login (legacy "A GSTR …" menu): the auditor's two registers — B2C = redemptions at
 * branches under the filing GSTIN, B2B = redemptions at dealer branches billed to the
 * dealer's GSTIN — each with a running INV NO that continues the auditor's sequence and
 * the redemption invoice no as REF NO, the GSTR-1 offline-tool workbook derived from the
 * same rows, and the input-side purchase GST.
 *
 * Distributor login (legacy "GSTR SALES B TO C" / "GSTR PURCHASE" menu): pinned to the
 * dealer's own branch — its sales to customers (INV NO = its own redemption invoice no,
 * no auditor sequence) and the same redemptions as purchases billed by HQ. The dealer's
 * "B TO B" of the legacy menu listed those purchase rows again, so it is not repeated.
 *
 * Source "legacy" (HQ only) reads the Lord DB for pre-go-live months; "new" reads
 * redemption_invoices.
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
        $this->form->fill(static::isDealer() ? ['from' => $from, 'to' => $to] : [
            'from' => $from,
            'to' => $to,
            'source' => GstAuditorReport::SOURCE_NEW,
            'b2c_start' => GstAuditorReport::nextNumber('b2c', $from, $to),
            'b2b_start' => GstAuditorReport::nextNumber('b2b', $from, $to),
        ]);
    }

    protected static function isDealer(): bool
    {
        return static::dealerBranchId() !== null;
    }

    public function form(Form $form): Form
    {
        if (static::isDealer()) {
            return $form->schema([
                Forms\Components\Section::make()->columns(2)->schema([
                    Forms\Components\DatePicker::make('from')->required()->native(false),
                    Forms\Components\DatePicker::make('to')->required()->native(false),
                ])->description(__('Your branch only: sales to customers under your GSTIN and the purchases HQ billed you for them.')),
            ])->statePath('data');
        }

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
        $source = static::isDealer() ? GstAuditorReport::SOURCE_NEW : ($d['source'] ?? GstAuditorReport::SOURCE_NEW);

        return new GstAuditorReport($source, $d['from'], $d['to'], $this->branchFilter());
    }

    public function run(): void
    {
        $d = $this->form->getState();
        [$from, $to] = [$d['from'], $d['to']];
        $branch = $this->branchFilter();
        $this->sections = [];
        $this->ran = true;
        $report = $this->report();

        if (static::isDealer()) {
            $this->section($report->dealerSalesRows(), __('Sales to customers (B2C) — redemption invoices under your GSTIN'), GstAuditorReport::B2C_COLUMNS);
            $this->section($report->dealerPurchaseRows(), __('Purchases from :hq — the same redemptions as HQ billed them', ['hq' => GstAuditorReport::filingBranchName()]), GstAuditorReport::PURCHASE_COLUMNS);
        } else {
            $this->section(GstAuditorReport::number($report->b2cRows(), (int) $d['b2c_start']),
                __('B2C — redemptions under GSTIN :g (consumer invoices)', ['g' => $report->gstin]), GstAuditorReport::B2C_COLUMNS);
            $this->section(GstAuditorReport::number($report->b2bRows(), (int) $d['b2b_start']),
                __('B2B — redemptions at dealer branches (billed to the dealer GSTIN)'), GstAuditorReport::B2B_COLUMNS);
        }
        if ($report->notes) {
            $this->sections[] = ['heading' => __('Notes'), 'columns' => [__('Note')], 'rows' => array_map(fn ($n) => [$n], $report->notes)];
        }

        $purchases = Purchase::with(['vendor', 'branch'])
            ->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderBy('purchase_date')->limit(1000)->get();
        if ($purchases->isNotEmpty() || ! static::isDealer()) {
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

    /** One register section: totals in the key/value strip, the first SCREEN_ROWS rows on screen. */
    protected function section(array $rows, string $heading, array $columns): void
    {
        $t = GstAuditorReport::totals($rows);
        $first = $columns[0];
        $kv = [
            __('Rows') => (string) $t['rows'],
            __($first === 'INV NO' ? 'INV NO range' : 'Invoices') => $t['rows'] ? ($first === 'INV NO' ? $rows[0]['INV NO'].' – '.end($rows)['INV NO'] : (string) $t['rows']) : '—',
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

    /** The GST workbook buttons for this login: [method, label, icon, color]. Rendered by report-page.blade. */
    public function gstExportButtons(): array
    {
        return static::isDealer()
            ? [
                ['exportSales', __('GSTR Sales B2C Excel'), 'heroicon-m-user', 'success'],
                ['exportPurchases', __('GSTR Purchase Excel'), 'heroicon-m-building-storefront', 'success'],
            ]
            : [
                ['exportB2c', __('GSTR B2C Excel'), 'heroicon-m-user', 'success'],
                ['exportB2b', __('GSTR B2B Excel'), 'heroicon-m-building-storefront', 'success'],
                ['exportGstr1', __('GSTR-1 Excel'), 'heroicon-m-document-check', 'warning'],
            ];
    }

    // ------------------------------------------------------------------ HQ exports (auditor files)

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
        abort_if(static::isDealer(), 403);
        $d = $this->form->getState();
        $report = $this->report();
        $start = (int) $d[$register.'_start'];
        $rows = GstAuditorReport::number($register === 'b2b' ? $report->b2bRows() : $report->b2cRows(), $start);
        GstAuditorReport::recordRun($register, $d['from'], $d['to'], $start, count($rows));

        return $this->download($register, $rows, 'GSTR REPORT '.strtoupper($register).' '.$this->periodLabel().'.xlsx');
    }

    /** GSTR-1 offline-tool workbook (B2B / B2CS / HSN) built from the same two registers. */
    public function exportGstr1()
    {
        abort_if(static::isDealer(), 403);
        $d = $this->form->getState();
        $report = $this->report();
        $b2c = GstAuditorReport::number($report->b2cRows(), (int) $d['b2c_start']);
        $b2b = GstAuditorReport::number($report->b2bRows(), (int) $d['b2b_start']);
        $path = tempnam(sys_get_temp_dir(), 'gstr1');
        GstAuditorWorkbook::gstr1($b2c, $b2b, $path);

        return response()->download($path, 'GSTR1 '.$this->periodLabel().'.xlsx')->deleteFileAfterSend();
    }

    // ------------------------------------------------------------------ distributor exports

    /** "GSTR SALES B2C <month>.xlsx" — the dealer's own sales register (its invoice numbers). */
    public function exportSales()
    {
        abort_unless(static::isDealer(), 403);

        return $this->download('sales', $this->report()->dealerSalesRows(), 'GSTR SALES B2C '.$this->periodLabel().'.xlsx');
    }

    /** "GSTR PURCHASE <month>.xlsx" — what HQ billed the dealer for those redemptions. */
    public function exportPurchases()
    {
        abort_unless(static::isDealer(), 403);

        return $this->download('purchase', $this->report()->dealerPurchaseRows(), 'GSTR PURCHASE '.$this->periodLabel().'.xlsx');
    }

    protected function download(string $register, array $rows, string $filename)
    {
        $path = tempnam(sys_get_temp_dir(), 'gst');
        GstAuditorWorkbook::register($register, $rows, $path);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    protected function periodLabel(): string
    {
        $d = $this->form->getState();
        $from = Carbon::parse($d['from']);
        $to = Carbon::parse($d['to']);

        return $from->isSameMonth($to) ? $from->format('M Y') : $from->format('d M Y').' to '.$to->format('d M Y');
    }
}
