<?php

namespace App\Filament\Pages\Reports;

use App\Support\BusinessModel\LegacyLordSource;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Employee Report (board 2026-09-18) — distributors on the Lord DB whose monthly
 * earnings cross the ESI / PF threshold, with the particulars needed to register them:
 *
 *   Earnings = GAP payout of the chosen run date (tbl_gap_log.grandtotal, the net figure
 *              after TDS + service tax) + IC commission billed in the chosen month
 *              (tbl_iccom.comamount, gross), both keyed by tbl_member.id.
 *
 * Every status (PAID and PENDING) counts. Members strictly above the threshold are listed
 * with name, father/husband, DOB, DOJ, contact, address, PAN, Aadhaar, bank and nominee
 * from tbl_member. Read-only on the legacy connection; admin / HQ only.
 */
class EmployeeReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Employee Report';

    protected static ?string $title = 'Employee Report';

    public const DEFAULT_THRESHOLD = 15000;

    /** Rows per table in the PDF / Print output (dompdf ≈ 10 s per 100 rows). */
    public const PDF_ROWS = 200;

    protected bool $pdfMode = false;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ! $u->isSupport() && ! $u->isDistributor();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Defaults: this month's GAP run (1st of the month) + last month's IC commission. */
    public function mount(): void
    {
        $this->form->fill([
            'gap_date' => now()->startOfMonth()->toDateString(),
            'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'threshold' => self::DEFAULT_THRESHOLD,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\DatePicker::make('gap_date')->label(__('GAP run date (tbl_gap_log)'))->required()->native(false)
                    ->helperText(__('The GAP payout run dated this day, all statuses.')),
                Forms\Components\DatePicker::make('from')->label(__('IC commission from (tbl_iccom)'))->required()->native(false),
                Forms\Components\DatePicker::make('to')->label(__('IC commission to'))->required()->native(false),
                Forms\Components\TextInput::make('threshold')->label(__('Earnings above (₹)'))->numeric()->minValue(0)->required()
                    ->default(self::DEFAULT_THRESHOLD)->helperText(__('PF wage ceiling ₹15,000 · ESI ₹21,000')),
            ]),
        ])->statePath('data');
    }

    /** PDF / Print re-run with PDF_ROWS per table; CSV / Excel carry every row. */
    protected function exportSections(): array
    {
        @set_time_limit(600);
        $this->run();

        return $this->sections;
    }

    public function exportPdf()
    {
        $this->pdfMode = true;

        return $this->sectionsPdf(false);
    }

    public function exportPrint()
    {
        $this->pdfMode = true;

        return $this->sectionsPdf(true);
    }

    public function run(): void
    {
        @ini_set('memory_limit', '1024M');
        $d = $this->form->getState();
        $gapDate = Carbon::parse($d['gap_date'])->toDateString();
        $from = Carbon::parse($d['from'])->startOfDay();
        $to = Carbon::parse($d['to'])->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $threshold = (float) ($d['threshold'] ?? self::DEFAULT_THRESHOLD);
        $this->sections = [];
        $this->ran = true;

        if (! LegacyLordSource::available()) {
            $this->sections[] = ['heading' => __('Data source unavailable'), 'kv' => [
                __('Problem') => __('The Lord DB (lordicl) could not be reached. Check the LEGACY_DB_* settings in .env.'),
            ]];

            return;
        }

        $db = DB::connection('legacy');

        // Earnings per member id — small aggregates, joined in PHP (legacy tables are unindexed).
        $gap = $db->table('tbl_gap_log')->where('date', $gapDate)
            ->selectRaw('userid, SUM(CAST(grandtotal AS DECIMAL(18,2))) t, SUM(CAST(nettotal AS DECIMAL(18,2))) gross, COUNT(*) n')
            ->groupBy('userid')->get()->keyBy(fn ($r) => (int) $r->userid);
        $ic = $db->table('tbl_iccom')->whereBetween('billdate', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('comid, SUM(CAST(comamount AS DECIMAL(18,2))) t, COUNT(*) n')
            ->groupBy('comid')->get()->keyBy(fn ($r) => (int) $r->comid);

        $totals = [];
        foreach ($gap as $id => $g) {
            $totals[$id] = ['gap' => (float) $g->t, 'gap_gross' => (float) $g->gross, 'ic' => 0.0];
        }
        foreach ($ic as $id => $i) {
            $totals[$id] ??= ['gap' => 0.0, 'gap_gross' => 0.0, 'ic' => 0.0];
            $totals[$id]['ic'] = (float) $i->t;
        }
        $over = collect($totals)->map(fn ($t, $id) => $t + ['id' => (int) $id, 'total' => round($t['gap'] + $t['ic'], 2)])
            ->filter(fn ($t) => $t['total'] > $threshold)
            ->sortByDesc('total')->values();

        $members = $over->isEmpty() ? collect() : collect($over->pluck('id')->chunk(500))
            ->flatMap(fn ($ids) => $db->table('tbl_member')->whereIn('id', $ids->all())->get([
                'id', 'userid', 'memname', 'fhname', 'dob', 'doj', 'address', 'city', 'pincode', 'mobileno', 'emailid',
                'panno', 'aadharno', 'bankname', 'bankacno', 'ifscno', 'nomineename', 'nomineerel', 'mylevelname', 'status',
            ]))->keyBy('id');

        $this->sections[] = ['heading' => __('Employee Report — earnings above ₹:t', ['t' => $this->money($threshold)]), 'kv' => [
            __('GAP run date') => $this->dmy($gapDate),
            __('IC commission period') => $this->dmy($from).' – '.$this->dmy($to),
            __('Earnings rule') => __('GAP payout (net of TDS + service tax) + IC commission (gross), all statuses'),
            __('Members paid GAP on the run') => (string) $gap->count().' / ₹'.$this->money($gap->sum('t')),
            __('Members earning IC in the period') => (string) $ic->count().' / ₹'.$this->money($ic->sum('t')),
            __('Members above the threshold') => (string) $over->count(),
            __('Their earnings') => '₹'.$this->money($over->sum('total')).' ('.__('GAP').' ₹'.$this->money($over->sum('gap')).' + '.__('IC').' ₹'.$this->money($over->sum('ic')).')',
            __('Members without a tbl_member row') => (string) $over->filter(fn ($t) => ! $members->has($t['id']))->count(),
        ]];

        $rows = [];
        foreach ($over as $i => $t) {
            $m = $members->get($t['id']);
            $rows[] = [
                (string) ($i + 1),
                $m?->memname ?: '—',
                $m?->userid ?: ('#'.$t['id']),
                $m?->fhname ?: '—',
                $this->legacyDate($m?->dob),
                $this->legacyDate($m?->doj),
                $m?->mobileno ?: '—',
                $m?->emailid ?: '—',
                $m ? trim(implode(', ', array_filter([trim((string) $m->address), trim((string) $m->city), trim((string) $m->pincode)]))) : '—',
                $m?->panno ? strtoupper(trim($m->panno)) : '—',
                $m?->aadharno ?: '—',
                $m?->bankname ?: '—',
                $m?->bankacno ?: '—',
                $m?->ifscno ? strtoupper(trim($m->ifscno)) : '—',
                $m && ($m->nomineename || $m->nomineerel) ? trim($m->nomineename.' ('.$m->nomineerel.')', ' ()') : '—',
                $m?->mylevelname ?: '—',
                $this->money($t['gap']),
                $this->money($t['ic']),
                $this->money($t['total']),
            ];
        }

        $kv = [
            __('Members listed') => (string) count($rows),
            __('GAP total (₹)') => $this->money($over->sum('gap')),
            __('IC total (₹)') => $this->money($over->sum('ic')),
            __('Earnings total (₹)') => $this->money($over->sum('total')),
        ];
        if ($this->pdfMode && count($rows) > self::PDF_ROWS) {
            $kv[__('Rows in this PDF')] = __('First :n of :total — CSV / Excel carry every row', ['n' => number_format(self::PDF_ROWS), 'total' => number_format(count($rows))]);
            $rows = array_slice($rows, 0, self::PDF_ROWS);
        }

        $this->sections[] = ['heading' => __('Distributors for ESI + PF registration'), 'kv' => $kv,
            'columns' => [__('#'), __('Name'), __('Member ID'), __('Father / Husband'), __('DOB'), __('DOJ'), __('Mobile'), __('Email'),
                __('Address'), __('PAN'), __('Aadhaar'), __('Bank'), __('Account No'), __('IFSC'), __('Nominee'), __('Level'),
                __('GAP (₹)'), __('IC (₹)'), __('Earnings (₹)')],
            'rows' => $rows];
    }

    /** Legacy dates are strings that may be empty or 0000-00-00. */
    protected function legacyDate($value): string
    {
        if (! $value || str_starts_with((string) $value, '0000')) {
            return '—';
        }
        try {
            return Carbon::parse($value)->format('d M Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
