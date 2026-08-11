<?php

namespace App\Filament\Pages\Reports;

use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\RdEntry;
use App\Models\RedemptionInvoice;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;

/**
 * Other Report — the agreed grab-bag (board spec 2026-08-09): stock movement,
 * stock transfers, RD collections, redemption register and the TDS/service-charge
 * deductions register, all period- and branch-filtered.
 */
class OtherReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Other Report';

    protected static ?string $title = 'Other Report';

    private const LIMIT = 500;

    public const REPORTS = [
        'stock_movements' => 'Stock movement',
        'stock_transfers' => 'Stock transfers',
        'rd_collections' => 'RD collections',
        'redemption_register' => 'Redemption register',
        'deductions' => 'Deductions (TDS + service charge)',
        'statutory' => 'Statutory register (PF / ESI / salary TDS)',
    ];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\Select::make('report')
                    ->options(self::REPORTS)
                    ->default('stock_movements')
                    ->required()
                    ->native(false),
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

        $branchNames = Branch::pluck('name', 'id');
        $bn = fn ($id) => $id ? ($branchNames[$id] ?? "#{$id}") : '—';

        match ($d['report'] ?? 'stock_movements') {
            'stock_movements' => $this->stockMovements($from, $to, $branch, $bn),
            'stock_transfers' => $this->stockTransfers($from, $to, $branch, $bn),
            'rd_collections' => $this->rdCollections($from, $to, $branch, $bn),
            'redemption_register' => $this->redemptions($from, $to, $branch, $bn),
            'deductions' => $this->deductions($from, $to, $branch),
            'statutory' => $this->statutory($from, $to),
            default => null,
        };
    }

    /**
     * PF / ESI / salary-TDS register (board 2026-08-11) — employee-wise per payroll
     * period, with employer contributions, ready for EPFO/ESIC/IT filing. The date
     * range selects payroll PERIODS (month of the from/to dates inclusive).
     */
    private function statutory($from, $to): void
    {
        $start = \Illuminate\Support\Carbon::parse($from)->startOfMonth();
        $end = \Illuminate\Support\Carbon::parse($to)->endOfMonth();

        $slips = \App\Models\Payslip::with(['employee.member', 'run'])
            ->whereHas('run', function ($q) use ($start, $end) {
                $q->whereRaw("STR_TO_DATE(CONCAT(period_year, '-', period_month, '-01'), '%Y-%m-%d') BETWEEN ? AND ?",
                    [$start->toDateString(), $end->toDateString()]);
            })
            ->limit(self::LIMIT)->get();

        $this->sections[] = ['heading' => self::REPORTS['statutory'],
            'kv' => [
                __('Payslips') => (string) $slips->count(),
                __('Gross') => $this->money($slips->sum('gross')),
                __('PF (employee)') => $this->money($slips->sum('pf_employee')),
                __('PF (employer)') => $this->money($slips->sum('pf_employer')),
                __('ESI (employee)') => $this->money($slips->sum('esi_employee')),
                __('ESI (employer)') => $this->money($slips->sum('esi_employer')),
                __('Salary TDS') => $this->money($slips->sum('tds')),
                __('Net paid') => $this->money($slips->sum('net')),
            ],
            'columns' => [__('Period'), __('Employee'), __('Name'), __('Gross'), __('Basic'), __('PF emp.'), __('PF empr.'), __('ESI emp.'), __('ESI empr.'), __('TDS'), __('Net'), __('Status')],
            'rows' => $slips->map(fn ($s) => [
                $s->run?->periodLabel() ?? ($s->run?->period_year . '-' . $s->run?->period_month),
                $s->employee?->employee_code,
                $s->employee?->member?->name,
                $this->money($s->gross), $this->money($s->basic),
                $this->money($s->pf_employee), $this->money($s->pf_employer),
                $this->money($s->esi_employee), $this->money($s->esi_employer),
                $this->money($s->tds), $this->money($s->net),
                ucfirst((string) $s->status),
            ])->all()];
    }

    private function stockMovements($from, $to, ?int $branch, callable $bn): void
    {
        $rows = StockMovement::with('catalogProduct')
            ->whereDate('moved_on', '>=', $from)->whereDate('moved_on', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->latest('moved_on')->latest('id')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => self::REPORTS['stock_movements'],
            'columns' => [__('Date'), __('Branch'), __('Product'), __('Type'), __('Qty change'), __('Balance after'), __('Reference')],
            'rows' => $rows->map(fn ($m) => [
                $this->dmy($m->moved_on), $bn($m->branch_id), Translatable::pick($m->catalogProduct?->name),
                ucfirst((string) $m->type), number_format((float) $m->qty_change, 4),
                number_format((float) $m->balance_after, 4),
                trim(($m->ref_type ?? '') . ' #' . ($m->ref_id ?? ''), ' #'),
            ])->all()];
    }

    private function stockTransfers($from, $to, ?int $branch, callable $bn): void
    {
        $rows = StockTransfer::with('catalogProduct')
            ->whereDate('transfer_date', '>=', $from)->whereDate('transfer_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w
                ->where('source_branch_id', $branch)->orWhere('destination_branch_id', $branch)))
            ->latest('transfer_date')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => self::REPORTS['stock_transfers'],
            'kv' => [
                __('Transfers') => (string) $rows->count(),
                __('Total value') => $this->money($rows->sum('transfer_value')),
                __('Total margin') => $this->money($rows->sum('margin_amount')),
            ],
            'columns' => [__('Transfer #'), __('Date'), __('From'), __('To'), __('Product'), __('Weight'), __('Qty'), __('Value'), __('Margin %'), __('Margin'), __('Status')],
            'rows' => $rows->map(fn ($t) => [
                $t->transfer_no, $this->dmy($t->transfer_date), $bn($t->source_branch_id), $bn($t->destination_branch_id),
                Translatable::pick($t->catalogProduct?->name), number_format((float) $t->weight, 4),
                number_format((float) $t->quantity, 3), $this->money($t->transfer_value),
                number_format((float) $t->margin_pct, 2), $this->money($t->margin_amount), ucfirst((string) $t->status),
            ])->all()];
    }

    private function rdCollections($from, $to, ?int $branch, callable $bn): void
    {
        $rows = RdEntry::with('member')
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->latest('paid_on')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => self::REPORTS['rd_collections'],
            'kv' => [__('Entries') => (string) $rows->count(), __('Collected') => $this->money($rows->sum('value'))],
            'columns' => [__('Paid on'), __('Member'), __('Bond'), __('Instalment #'), __('Amount'), __('Branch')],
            'rows' => $rows->map(fn ($r) => [
                $this->dmy($r->paid_on), $r->member?->member_code, (string) $r->bond_id,
                (string) $r->due_count, $this->money($r->value), $bn($r->branch_id),
            ])->all()];
    }

    private function redemptions($from, $to, ?int $branch, callable $bn): void
    {
        $rows = RedemptionInvoice::with('member')
            ->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->latest('invoice_date')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => self::REPORTS['redemption_register'],
            'kv' => [__('Invoices') => (string) $rows->count(), __('Grand total') => $this->money($rows->sum('grand_total'))],
            'columns' => [__('Invoice #'), __('Date'), __('Member'), __('Branch'), __('Taxable'), __('CGST'), __('SGST'), __('Grand total'), __('Payment')],
            'rows' => $rows->map(fn ($i) => [
                $i->invoice_no, $this->dmy($i->invoice_date), $i->member?->member_code, $bn($i->branch_id),
                $this->money($i->taxable_total), $this->money($i->cgst), $this->money($i->sgst),
                $this->money($i->grand_total), strtoupper((string) $i->payment_mode),
            ])->all()];
    }

    private function deductions($from, $to, ?int $branch): void
    {
        $rows = CommissionLedger::with('member')
            ->where('status', '!=', 'pending')
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->where(fn ($w) => $w->where('tds', '>', 0)->orWhere('service_charge', '>', 0))
            ->latest('paid_on')->limit(self::LIMIT)->get();
        $this->sections[] = ['heading' => self::REPORTS['deductions'],
            'kv' => [
                __('Entries') => (string) $rows->count(),
                __('Gross') => $this->money($rows->sum('amount')),
                __('TDS withheld') => $this->money($rows->sum('tds')),
                __('Service charge') => $this->money($rows->sum('service_charge')),
                __('Net paid') => $this->money($rows->sum('net_amount')),
            ],
            'columns' => [__('Paid on'), __('Member'), __('Stream'), __('Gross'), __('TDS'), __('Service'), __('Net')],
            'rows' => $rows->map(fn ($r) => [
                $this->dmy($r->paid_on), $r->member?->member_code, $r->type, $this->money($r->amount),
                $this->money($r->tds), $this->money($r->service_charge), $this->money($r->net_amount),
            ])->all()];
    }
}
