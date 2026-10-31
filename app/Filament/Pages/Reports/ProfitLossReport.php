<?php

namespace App\Filament\Pages\Reports;

use App\Support\Accounts\LegacyProfitLossSource;
use App\Support\Accounts\ManualLedger;
use App\Support\Accounts\MetalUnits;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Support\Carbon;

/**
 * Profit & Loss Report (Lord board 2026-10-21) — read from the legacy Lord DB:
 *
 *   1. Scheme-wise incoming amount from bond allocation / investment
 *   2. Payout distribution classification (sales profit, Sales Incentive, Promotional
 *      Incentive, GAP / level income, Billing Margin, digi-gold withdrawals, QR redemptions)
 *   3. Lord's stock maintenance (HQ vs dealer network, gold / silver / cash, coverage)
 *   4. Professional statement (income → cost of goods → distributions → expenses → result)
 *   + a month-by-month trend and the notes.
 *
 * Operating expenses and tax are the audit inputs saved on the Balance Sheet Report for the
 * financial year of the To date — they are read here, not edited.
 */
class ProfitLossReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Profit & Loss Report';

    protected static ?string $title = 'Profit & Loss Report';

    public const STATEMENTS = [
        'full' => 'Full report (all sections)',
        'scheme' => '1. Scheme-wise incoming',
        'payouts' => '2. Payout distribution classification',
        'stock' => "3. Lord's stock maintenance",
        'statement' => '4. Professional statement',
        'monthly' => 'Month-by-month trend',
    ];

    public const SCREEN_ROWS = 500;

    public const PDF_ROWS = 100;

    protected bool $allRows = false;

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

    protected function source(): LegacyProfitLossSource
    {
        return new LegacyProfitLossSource;
    }

    public function mount(): void
    {
        $to = now()->startOfDay();
        $this->form->fill([
            'statement' => 'full',
            'from' => MetalUnits::fyStart($to)->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(5)->schema([
                Forms\Components\Select::make('statement')->label(__('Section'))
                    ->options(collect(self::STATEMENTS)->map(fn ($l) => __($l))->all())
                    ->required()->native(false)->selectablePlaceholder(false),
                Forms\Components\DatePicker::make('from')->label(__('From date'))->required()->native(false),
                Forms\Components\DatePicker::make('to')->label(__('To date'))->required()->native(false),
                Forms\Components\Select::make('branch_id')->label(__('Branch (Lord DB)'))
                    ->options(function () {
                        try {
                            return $this->source()->branchNames();
                        } catch (\Throwable) {
                            return [];
                        }
                    })
                    ->placeholder(__('All branches'))->searchable()->native(false),
                Forms\Components\Placeholder::make('hint')->label(__('Source'))
                    ->content(__('Lord DB (lordicl). Operating expenses and tax come from the audit inputs saved on the Balance Sheet Report for the financial year of the To date.')),
            ]),
        ])->statePath('data');
    }

    // ---------------------------------------------------------------- exports

    protected function exportSections(): array
    {
        @set_time_limit(600);
        $this->allRows = ! $this->pdfMode;
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

    protected function sectionsPdf(bool $inline)
    {
        @ini_set('memory_limit', '1024M');
        $sections = $this->exportSections();
        $d = $this->form->getState();
        $title = __('Profit & Loss Report').' · '.$this->dmy($d['from']).' – '.$this->dmy($d['to']);

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'], true)
            ->loadView('pdf.sections', ['title' => $title, 'sections' => $sections, 'generatedAt' => now()->toDayDateTimeString()])
            ->setPaper('a4', 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print ($output),
            $this->exportSlug().'-'.($d['statement'] ?? 'full').'-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
            $inline ? 'inline' : 'attachment',
        );
    }

    protected function capRows(array &$kv, array $rows): array
    {
        $cap = $this->pdfMode ? self::PDF_ROWS : self::SCREEN_ROWS;
        if (($this->allRows && ! $this->pdfMode) || count($rows) <= $cap) {
            return $rows;
        }
        $kv[__('Rows shown')] = __(':shown of :total — CSV / Excel carry every row', ['shown' => $cap, 'total' => count($rows)]);

        return array_slice($rows, 0, $cap);
    }

    // ---------------------------------------------------------------- run

    public function run(): void
    {
        $d = $this->form->getState();
        $from = Carbon::parse($d['from'])->startOfDay();
        $to = Carbon::parse($d['to'])->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $branch = ($d['branch_id'] ?? null) ? (int) $d['branch_id'] : null;
        $statement = (string) ($d['statement'] ?? 'full');

        $this->sections = [];
        $this->ran = true;

        if (! LegacyProfitLossSource::available()) {
            $this->sections[] = ['heading' => __('The Lord DB (lordicl) is not reachable'),
                'kv' => [__('Check') => __('The legacy connection in config/database.php (LEGACY_DB_*) must point at the live Lord database.')]];

            return;
        }

        try {
            $f = $this->figures($this->source(), $from, $to, $branch);
        } catch (\Throwable $e) {
            report($e);
            $this->sections[] = ['heading' => __('The Lord DB could not be read'), 'kv' => [__('Error') => $e->getMessage()]];

            return;
        }

        $this->sections = $this->sectionsFor($f, $statement);
    }

    /** The sheets for a section choice, from assembled figures (see figures()). */
    public function sectionsFor(array $f, string $statement = 'full'): array
    {
        $all = $statement === 'full';
        $want = fn (string $s) => $all || $statement === $s;
        $out = [];

        if ($want('scheme')) {
            $out[] = $this->schemeSection($f);
            $out[] = $this->contractStatusSection($f);
            $out[] = $this->schemeRulesSection($f);
        }
        if ($want('payouts')) {
            $out[] = $this->payoutSection($f);
            $out[] = $this->salesProfitSection($f);
        }
        if ($want('stock')) {
            $out[] = $this->stockSection($f);
        }
        if ($want('statement')) {
            $out[] = $this->statementSection($f);
        }
        if ($want('monthly')) {
            $out[] = $this->monthlySection($f);
        }
        // The conclusion closes the report (board 2026-10-21: "at the end"), followed only by the notes.
        if ($all || $statement === 'statement') {
            $out[] = $this->conclusionSection($f);
        }
        $out[] = $this->notesSection($f);

        return $out;
    }

    // ---------------------------------------------------------------- figures

    /**
     * Everything the sections need, read once. Keys: from, to, branch, scheme (rows), payouts,
     * sales (per product), invoices, stock (per branch), stockNow (gold/silver grams + cost),
     * cash, purchases (window), monthly, pending_qrs, rates, inputs (audit inputs), fy.
     */
    protected function figures(LegacyProfitLossSource $src, Carbon $from, Carbon $to, ?int $branch): array
    {
        $fy = MetalUnits::fy($to);
        $sales = $src->salesByMetal($from, $to, $branch);
        $stock = $src->stockByBranch($branch);
        $purchases = $src->purchases($to, $branch)->filter(fn ($p) => $p->date->between($from, $to))->values();
        $purchaseMetal = $src->purchaseLinesByMetal($from, $to, $branch)->keyBy('metal');

        return [
            'from' => $from,
            'to' => $to,
            'branch' => $branch,
            'fy' => $fy,
            'scheme' => $src->schemeIncoming($from, $to, $branch),
            'rules' => $src->planRules(),
            'payouts' => $src->payouts($from, $to, $branch),
            'sales' => $sales,
            'invoices' => $src->invoiceTotals($from, $to, $branch),
            'stock' => $stock,
            'cash' => $src->cashInHand($branch),
            'purchases' => $purchases,
            'purchaseMetal' => ['gold' => (float) ($purchaseMetal['gold']->value ?? 0), 'silver' => (float) ($purchaseMetal['silver']->value ?? 0),
                'gold_g' => (float) ($purchaseMetal['gold']->grams ?? 0), 'silver_g' => (float) ($purchaseMetal['silver']->grams ?? 0)],
            'monthly' => $src->monthly($from, $to, $branch),
            'pending_qrs' => $src->pendingQrs($to, $branch),
            'rates' => $src->liveRates(),
            'inputs' => ManualLedger::load($fy),
            'source_notes' => $src->notes(),
        ];
    }

    /** The statement lines, derived from the figures — kept separate so the arithmetic can be tested. */
    public function statementLines(array $f): array
    {
        $m = fn (string $k) => (float) ($f['inputs'][$k] ?? 0);
        $p = $f['payouts'];
        $sales = $f['sales'];
        $metal = (float) $sales->where('metal', 'gold')->sum('metal_value') + (float) $sales->where('metal', 'silver')->sum('metal_value');
        $charges = (float) $sales->where('metal', 'gold')->sum('charges') + (float) $sales->where('metal', 'silver')->sum('charges');
        $invested = (float) $f['scheme']->sum('invested');
        $installments = (float) $f['scheme']->sum('installments');
        $allocation = (float) $f['scheme']->sum('allocation');

        $l = [];
        $l['sales_metal'] = $metal;
        $l['sales_profit'] = $charges;
        $l['invested'] = $invested;
        $l['installments'] = $installments;
        $l['total_income'] = $metal + $charges + $invested + $installments;

        $l['metal_cost'] = $metal;
        $l['allocation'] = $allocation;
        $l['holding'] = (float) $f['scheme']->sum('holding');
        // Money on 100%-holding schemes (G12): kept by Lord, gold falls due at the 12-month settlement.
        $l['forward_holding'] = (float) $f['scheme']->where('is_holding', true)->sum('invested');
        $l['gross_margin'] = $l['total_income'] - $metal - $allocation;

        $l['ic'] = $p['ic']['paid'] + $p['ic']['pending'];
        $l['cbc'] = $p['cbc']['paid'];
        $l['gap_gross'] = $p['gap']['gross'];
        $l['gap_withheld'] = $p['gap']['tds'];
        $l['billing'] = $p['billing']['paid'] + $p['billing']['pending'];
        $l['total_distributions'] = $l['ic'] + $l['cbc'] + $l['gap_gross'] + $l['billing'];
        $l['after_distributions'] = $l['gross_margin'] - $l['total_distributions'];

        $l['salaries'] = $m('salaries_extra');
        $l['rent'] = $m('rent');
        $l['electricity'] = $m('electricity');
        $l['bank_charges'] = $m('bank_charges');
        $l['marketing'] = $m('marketing');
        $l['insurance'] = $m('insurance');
        $l['repairs'] = $m('repairs');
        $l['depreciation'] = $m('depreciation');
        $l['professional_fees'] = $m('professional_fees');
        $l['other_expenses'] = $m('other_expenses') + $m('manufacturing_charges') + $m('freight_inward');
        $l['total_expenses'] = $l['salaries'] + $l['rent'] + $l['electricity'] + $l['bank_charges'] + $l['marketing'] + $l['insurance']
            + $l['repairs'] + $l['depreciation'] + $l['professional_fees'] + $l['other_expenses'];

        // Board 2026-10-21: 20% TDS is deducted from every GAP payout and is a government liability, so the
        // distribution stays at gross and the TDS appears as a memo line (payable to government).
        $l['gap_tds'] = $p['gap']['tds'];
        $l['pbt'] = $l['after_distributions'] - $l['total_expenses'];
        $l['income_tax'] = $m('income_tax');
        $l['net_result'] = $l['pbt'] - $l['income_tax'];

        $l['gst'] = $f['invoices']['cgst'] + $f['invoices']['sgst'];
        $l['digi_withdrawals'] = $p['digi']['paid'];
        $l['qr_redeemed'] = $p['qr_redeemed']['value'];
        $l['qr_issued'] = $p['qr_issued']['value'];

        return $l;
    }

    // ---------------------------------------------------------------- sections

    protected function grams(float $g): string
    {
        return number_format($g, 3).' g';
    }

    protected function pct(float $part, float $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 2).' %' : '—';
    }

    /** The closing verdict: profit or loss, how big, and what drove it. */
    protected function conclusionSection(array $f): array
    {
        $l = $this->statementLines($f);
        $profit = $l['net_result'] >= 0;
        $verdict = $profit ? __('NET PROFIT') : __('NET LOSS');
        $amount = $this->money(abs($l['net_result']));
        $incoming = $l['invested'] + $l['installments'];
        $share = fn (float $v) => $this->pct(abs($v), $l['total_income']);

        $drivers = [];
        if ($l['allocation'] > 0) {
            $drivers[] = __('gold-stock allocation of :v (:p of the investment received)', ['v' => $this->money($l['allocation']), 'p' => $this->pct($l['allocation'], $l['invested'])]);
        }
        if ($l['total_distributions'] > 0) {
            $drivers[] = __('distributions to members and dealers of :v (:p of the scheme money received)', ['v' => $this->money($l['total_distributions']), 'p' => $this->pct($l['total_distributions'], $incoming)]);
        }
        if ($l['total_expenses'] > 0) {
            $drivers[] = __('operating expenses of :v', ['v' => $this->money($l['total_expenses'])]);
        }
        $why = $profit
            ? __('Income of :income covers :drivers and leaves a surplus.', ['income' => $this->money($l['total_income']), 'drivers' => implode(', ', $drivers) ?: __('all charges')])
            : __('Income of :income does not cover :drivers.', ['income' => $this->money($l['total_income']), 'drivers' => implode(', ', $drivers) ?: __('the charges')]);

        $rows = [
            [__('Total income'), $this->money($l['total_income']), $share($l['total_income'])],
            [__('Less: metal cost of jewellery sold'), $this->money(-$l['metal_cost']), $share($l['metal_cost'])],
            [__('Less: gold-stock allocated to scheme customers'), $this->money(-$l['allocation']), $share($l['allocation'])],
            [__('Gross margin'), $this->money($l['gross_margin']), $share($l['gross_margin'])],
            [__('Less: distributions (IC + CBC + GAP + billing margin)'), $this->money(-$l['total_distributions']), $share($l['total_distributions'])],
            [__('Less: operating expenses (audit inputs)'), $this->money(-$l['total_expenses']), $share($l['total_expenses'])],
            [__('Less: income tax'), $this->money(-$l['income_tax']), $share($l['income_tax'])],
            [$verdict, $this->money($l['net_result']), $share($l['net_result'])],
        ];

        return [
            'heading' => __('Conclusion — :verdict of :amount (:from – :to)', ['verdict' => $verdict, 'amount' => $amount, 'from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [
                __('Result') => $verdict.' '.$amount,
                __('On total income') => $this->pct($l['net_result'], $l['total_income']),
                __('Sales profit (Lord\'s own margin)') => $this->money($l['sales_profit']),
                __('Why') => $why,
            ],
            'columns' => [__('Particulars'), __('Amount (₹)'), __('Share of income')],
            'rows' => $rows,
        ];
    }

    protected function schemeSection(array $f): array
    {
        $rows = [];
        $i = 0;
        foreach ($f['scheme'] as $r) {
            $incoming = $r->invested + $r->installments;
            $rows[] = [++$i, $r->scheme, number_format($r->contracts), $this->money($r->invested), number_format($r->installment_count), $this->money($r->installments),
                $this->money($incoming), $this->money($r->allocation), $this->pct($r->allocation, $r->invested), $this->money($r->holding), $this->money($r->cbc_provision),
                $this->money($r->ic_provision), $this->money($incoming - $r->allocation - $r->cbc_provision - $r->ic_provision), $r->treatment];
        }
        $s = $f['scheme'];
        $invested = (float) $s->sum('invested');
        $installments = (float) $s->sum('installments');
        $allocation = (float) $s->sum('allocation');
        $holding = (float) $s->sum('holding');
        $cbc = (float) $s->sum('cbc_provision');
        $ic = (float) $s->sum('ic_provision');
        $rows[] = ['', __('TOTAL'), number_format((int) $s->sum('contracts')), $this->money($invested), number_format((int) $s->sum('installment_count')), $this->money($installments),
            $this->money($invested + $installments), $this->money($allocation), $this->pct($allocation, $invested), $this->money($holding), $this->money($cbc), $this->money($ic),
            $this->money($invested + $installments - $allocation - $cbc - $ic), ''];

        return [
            'heading' => __('1. Scheme-wise incoming amount — bond allocation / investment (:from – :to)', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [
                __('Contracts started') => number_format((int) $s->sum('contracts')),
                __('Active contracts') => number_format((int) $s->sum('active_contracts')),
                __('Closed (inactive) contracts') => number_format((int) $s->sum('closed_contracts')),
                __('Investment received') => $this->money($invested),
                __('Gold Saving installments received') => $this->money($installments),
                __('Total incoming') => $this->money($invested + $installments),
                __('Gold-stock allocation') => $this->money($allocation),
                __('Allocation share of investment') => $this->pct($allocation, $invested),
                __('Held by Lord (holding)') => $this->money($holding),
                __('CBC + IC provisions') => $this->money($cbc + $ic),
                __('Retained after allocation + provisions') => $this->money($invested + $installments - $allocation - $cbc - $ic),
            ],
            'columns' => ['#', __('Scheme'), __('Contracts'), __('Investment received (₹)'), __('Installments (no.)'), __('Gold Saving installments (₹)'), __('Total incoming (₹)'),
                __('Gold-stock allocation (₹)'), __('Allocation %'), __('Holding (₹)'), __('CBC provision (₹)'), __('IC provision (₹)'), __('Retained (₹)'), __('Treatment')],
            'rows' => $rows,
        ];
    }

    /** Board 2026-10-21: scheme facts come from the LEGACY plan master only (tbl_planjewel), never the new plans table. */
    protected function schemeRulesSection(array $f): array
    {
        $used = $f['scheme']->pluck('planid')->all();
        $rows = [];
        $i = 0;
        foreach ($f['rules'] as $rule) {
            if (! in_array($rule->planid, $used, true)) {
                continue;
            }
            $gold = $rule->is_holding ? 0.0 : $rule->allocation_pct;
            $promised = $gold + $rule->cbc_total_pct + $rule->ic_total_pct + $rule->level_total_pct * max($rule->level_months, 1) + $rule->billing_margin_pct;
            $rows[] = [++$i, $rule->name, $this->money($rule->min_value),
                $rule->is_holding ? __('Holding 100% (gold at 12-month settlement)') : number_format($rule->allocation_pct, 2).' %',
                $rule->cbc_pct > 0 ? number_format($rule->cbc_pct, 2).' % × '.$rule->cbc_months.' = '.number_format($rule->cbc_total_pct, 2).' %' : '—',
                $rule->ic_total_pct > 0 ? number_format($rule->ic_total_pct, 2).' % ('.count($rule->ic).' '.__('levels').')' : '—',
                $rule->level_total_pct > 0 ? number_format($rule->level_total_pct, 2).' % × '.$rule->level_months.' '.__('months') : '—',
                number_format($rule->billing_margin_pct, 2).' %', number_format($rule->gm_margin_pct, 2).' %',
                number_format($promised, 2).' %'];
        }

        return [
            'heading' => __('1b. Scheme rules — legacy plan master (tbl_planjewel)'),
            'kv' => [
                __('Basis') => __('allocation = gold-stock share at billing; cbvalue × cbcount = CBC vouchers over the term; iccom = Sales Incentive schedule; levelcom × levelcomduration = GAP over the term; billingmargin / gmmargin = dealer margins'),
                __('Promised per ₹100') => __('gold stock + CBC total + IC total + GAP total + billing margin, as the plan master defines it'),
            ],
            'columns' => ['#', __('Scheme'), __('Min value (₹)'), __('Gold-stock allocation'), __('CBC (per month × months)'), __('Sales Incentive (IC)'), __('GAP / level'), __('Billing margin'), __('GM margin'), __('Promised per ₹100')],
            'rows' => $rows,
        ];
    }

    /** Board 2026-10-21: every scheme split into running (active) and closed (inactive) contracts. */
    protected function contractStatusSection(array $f): array
    {
        $rows = [];
        $i = 0;
        foreach ($f['scheme'] as $r) {
            if ($r->contracts === 0) {
                continue;
            }
            $rows[] = [++$i, $r->scheme, number_format($r->contracts),
                number_format($r->active_contracts), $this->money($r->active_invested), $this->money($r->active_allocation),
                number_format($r->closed_contracts), $this->money($r->closed_invested), $this->money($r->closed_allocation),
                $this->pct($r->active_contracts, $r->contracts), $this->money($r->returns)];
        }
        $s = $f['scheme'];
        $rows[] = ['', __('TOTAL'), number_format((int) $s->sum('contracts')),
            number_format((int) $s->sum('active_contracts')), $this->money((float) $s->sum('active_invested')), $this->money((float) $s->sum('active_allocation')),
            number_format((int) $s->sum('closed_contracts')), $this->money((float) $s->sum('closed_invested')), $this->money((float) $s->sum('closed_allocation')),
            $this->pct((float) $s->sum('active_contracts'), (float) $s->sum('contracts')), $this->money((float) $s->sum('returns'))];

        return [
            'heading' => __('1a. Contract status per scheme — active vs closed (inactive) contracts started :from – :to', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [
                __('Active') => number_format((int) $s->sum('active_contracts')).' · '.$this->money((float) $s->sum('active_invested')),
                __('Closed (inactive)') => number_format((int) $s->sum('closed_contracts')).' · '.$this->money((float) $s->sum('closed_invested')),
                __('Basis') => __('tbl_bond bondstatus 1 = running; 0 = closed by the monthly script once the term elapsed'),
            ],
            'columns' => ['#', __('Scheme'), __('Contracts'), __('Active (no.)'), __('Active investment (₹)'), __('Active allocation (₹)'),
                __('Closed (no.)'), __('Closed investment (₹)'), __('Closed allocation (₹)'), __('Active share'), __('Return payments (₹)')],
            'rows' => $rows,
        ];
    }

    protected function payoutSection(array $f): array
    {
        $p = $f['payouts'];
        $l = $this->statementLines($f);
        $row = fn (string $class, string $basis, float $paid, float $pending, float $withheld, string $count = '') => [
            $class, $basis, $this->money($paid), $this->money($pending), $this->money($paid + $pending), $this->money($withheld), $this->money($paid + $pending - $withheld), $count,
        ];
        // GAP: paid / pending are the NET amounts handed over; total is the gross run; withheld = TDS + service charge.
        $gapRow = [__('GAP / Level income'), __('tbl_memberearning_level — GAP income per member by run date; 20% TDS deducted before payout'), $this->money($p['gap']['paid']), $this->money($p['gap']['pending']),
            $this->money($p['gap']['gross']), $this->money($p['gap']['tds']), $this->money($p['gap']['paid'] + $p['gap']['pending']), number_format($p['gap']['count'])];
        $rows = [
            $row(__('Sales profit (retained by Lord)'), __('Making + wastage charges on jewellery sales — Lord\'s own margin, not a payout'), $l['sales_profit'], 0, 0, number_format((int) $f['sales']->where('metal', '<>', 'cash')->sum('pieces')).' '.__('pcs')),
            $row(__('Sales Incentive (IC)'), __('tbl_iccom — per bill, five uplines; paid via digi transfer'), $p['ic']['paid'], $p['ic']['pending'], 0, number_format($p['ic']['count'])),
            $row(__('Promotional Incentive (CBC)'), __('tbl_cbc — monthly gold vouchers falling due in the period (issued = PASSED)'), $p['cbc']['paid'], $p['cbc']['pending'], 0, number_format($p['cbc']['count'])),
            $gapRow,
            $row(__('Billing Margin (dealers)'), __('tbl_reseller_com type 1 — billing reference margin per bill'), $p['billing']['paid'], $p['billing']['pending'], 0, number_format((int) $p['billing']['count'])),
        ];
        // Board 2026-10-21: digi-gold withdrawals and gold-QR redemptions are NOT payout classes — the
        // first repeats money already classified above, the second is the customer's own gold exchanged.

        $totalPayout = $l['ic'] + $l['cbc'] + $l['gap_gross'] + $l['billing'];
        $rows[] = [__('TOTAL member / dealer distributions'), __('IC + CBC + GAP (gross) + Billing Margin'), '', '', $this->money($totalPayout), $this->money($l['gap_withheld']), $this->money($totalPayout - $l['gap_withheld']), ''];

        return [
            'heading' => __('2. Payout distribution classification (:from – :to)', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [
                __('Sales profit (Lord)') => $this->money($l['sales_profit']),
                __('Sales Incentive (IC)') => $this->money($l['ic']),
                __('Promotional Incentive (CBC)') => $this->money($l['cbc']),
                __('GAP / Level income (gross)') => $this->money($l['gap_gross']),
                __('Billing Margin') => $this->money($l['billing']),
                __('Total distributions') => $this->money($totalPayout),
                __('TDS 20% on GAP (payable to government)') => $this->money($p['gap']['tds']),
                __('Distributions vs incoming') => $this->pct($totalPayout, $l['invested'] + $l['installments']),
            ],
            'columns' => [__('Class'), __('Basis'), __('Paid / issued (₹)'), __('Pending (₹)'), __('Total (₹)'), __('TDS 20% (₹)'), __('Net to members (₹)'), __('Count / weight')],
            'rows' => $rows,
        ];
    }

    protected function salesProfitSection(array $f): array
    {
        $rows = [];
        foreach (['gold', 'silver'] as $metal) {
            $s = $f['sales']->where('metal', $metal);
            $mv = (float) $s->sum('metal_value');
            $ch = (float) $s->sum('charges');
            $rows[] = [ucfirst($metal), number_format((float) $s->sum('pieces')), $this->grams((float) $s->sum('grams')), $this->money($mv), $this->money($ch), $this->money($mv + $ch), $this->pct($ch, $mv)];
        }
        $cash = (float) $f['sales']->where('metal', 'cash')->sum('taxable');
        $rows[] = [__('Scheme / contract (CASH product)'), '—', '—', $this->money($cash), $this->money(0), $this->money($cash), '—'];

        return [
            'heading' => __('2a. Sales profit by metal — making + wastage charges on jewellery sales'),
            'kv' => [
                __('Invoices') => number_format($f['invoices']['count']),
                __('Taxable turnover') => $this->money($f['invoices']['taxable']),
                __('GST collected (CGST + SGST)') => $this->money($f['invoices']['cgst'] + $f['invoices']['sgst']),
            ],
            'columns' => [__('Metal'), __('Pieces'), __('Weight'), __('Metal value (₹)'), __('Sales profit / charges (₹)'), __('Taxable (₹)'), __('Margin on metal')],
            'rows' => $rows,
        ];
    }

    protected function stockSection(array $f): array
    {
        // Board 2026-10-21: LORD JEWELLER (#1) is never part of the stock portion — only the dealer network counts.
        $stock = $f['stock']->where('is_hq', false)->values();
        $excluded = $f['stock']->where('is_hq', true);
        $sum = fn ($c, $k) => (float) $c->sum($k);
        $goldG = $sum($stock, 'gold_g');
        $pendingQrG = (float) $f['pending_qrs']['grams'];

        $rows = $stock->map(fn ($r) => [
            $r->is_hq ? __('Head office') : __('Dealer'), $r->name.' (#'.$r->id.')', number_format($r->gold_pcs), $this->grams($r->gold_g), $this->money($r->gold_cost),
            number_format($r->silver_pcs), $this->grams($r->silver_g), $this->money($r->silver_cost), $this->money($r->cash), $this->money($r->gold_cost + $r->silver_cost + $r->cash),
        ])->all();
        $kv = [
            __('Dealer branches holding stock') => number_format($stock->count()),
            __('Gold in dealer network') => $this->grams($goldG).' · '.$this->money($sum($stock, 'gold_cost')),
            __('Silver in dealer network') => $this->grams($sum($stock, 'silver_g')).' · '.$this->money($sum($stock, 'silver_cost')),
            __('Cash in dealer network') => $this->money($sum($stock, 'cash')),
            __('Excluded') => __('LORD JEWELLER (#1) — head-office holdings are not part of the stock portion').($excluded->isEmpty() ? '' : ' ('.$this->grams($sum($excluded, 'gold_g')).' '.__('gold held there').')'),
            __('Gold bought in the period') => $this->grams($f['purchaseMetal']['gold_g']).' · '.$this->money($f['purchaseMetal']['gold']),
            __('Gold sold in the period') => $this->grams((float) $f['sales']->where('metal', 'gold')->sum('grams')),
            __('Undelivered gold QRs') => $this->grams($pendingQrG).' · '.$this->money((float) $f['pending_qrs']['value']),
            __('Physical gold vs QR obligation') => $this->pct($goldG, $pendingQrG),
            __('Gold at live rate') => $this->money($goldG * (float) $f['rates']['gold']),
        ];

        return [
            'heading' => __("3. Lord's stock maintenance — dealer network as the book stands on :date", ['date' => $this->dmy(now())]),
            'kv' => $kv,
            'columns' => [__('Level'), __('Branch'), __('Gold pcs'), __('Gold (g)'), __('Gold cost (₹)'), __('Silver pcs'), __('Silver (g)'), __('Silver cost (₹)'), __('Cash (₹)'), __('Total value (₹)')],
            'rows' => $this->capRows($kv, $rows),
        ];
    }

    protected function statementSection(array $f): array
    {
        $l = $this->statementLines($f);
        $r = fn (string $label, $v, string $basis = '') => [$label, $v === null ? '' : $this->money($v), $basis];
        $rows = [
            $r(__('A. INCOME'), null),
            $r(__('Jewellery sales — metal value (gold + silver)'), $l['sales_metal'], __('tbl_sales_cart, metal at the day\'s rate')),
            $r(__('Making & wastage charges (sales profit)'), $l['sales_profit'], __('line total − metal value')),
            $r(__('Scheme investment received (new contracts)'), $l['invested'], __('tbl_bond epinvalue')),
            $r(__('Gold Saving installments received'), $l['installments'], __('tbl_rdentry')),
            $r(__('Total income'), $l['total_income']),
            $r(__('B. COST OF GOODS & ALLOCATIONS'), null),
            $r(__('Metal cost of jewellery sold'), -$l['metal_cost'], __('metal value at sale = cost')),
            $r(__('Gold-stock allocated to scheme customers'), -$l['allocation'], __('tbl_bond bvalue — obligation created')),
            $r(__('Gross margin'), $l['gross_margin']),
            $r(__('C. DISTRIBUTIONS TO MEMBERS & DEALERS'), null),
            $r(__('Sales Incentive (IC)'), -$l['ic'], __('paid + pending')),
            $r(__('Promotional Incentive (CBC) issued'), -$l['cbc'], __('gold vouchers due in the period')),
            $r(__('GAP / Level income (gross)'), -$l['gap_gross'], __('tbl_memberearning_level, before 20% TDS')),
            $r(__('Billing Margin to dealers'), -$l['billing']),
            $r(__('Total distributions'), -$l['total_distributions']),
            $r(__('Result after distributions'), $l['after_distributions']),
            $r(__('D. OPERATING EXPENSES (audit inputs FY :fy)', ['fy' => $f['fy']]), null),
            $r(__('Employee salaries'), -$l['salaries']),
            $r(__('Rent & maintenance'), -$l['rent']),
            $r(__('Electricity'), -$l['electricity']),
            $r(__('Bank charges'), -$l['bank_charges']),
            $r(__('Marketing / advertisement'), -$l['marketing']),
            $r(__('Insurance'), -$l['insurance']),
            $r(__('Repairs & maintenance'), -$l['repairs']),
            $r(__('Depreciation'), -$l['depreciation']),
            $r(__('Professional / audit fees'), -$l['professional_fees']),
            $r(__('Other expenses (incl. goldsmith, freight)'), -$l['other_expenses']),
            $r(__('Total operating expenses'), -$l['total_expenses']),
            $r(__('E. RESULT'), null),
            $r(__('Profit before tax'), $l['pbt']),
            $r(__('Income tax / current tax'), -$l['income_tax'], __('audit input')),
            $r(__('NET RESULT'), $l['net_result']),
            $r(__('F. MEMO (not in the result)'), null),
            $r(__('GST collected (CGST + SGST)'), $l['gst'], __('payable to government')),
            $r(__('TDS 20% withheld on GAP (payable to government)'), $l['gap_tds'], __('deducted from the GAP payouts above')),
            $r(__('Gold QRs allotted on bills'), $l['qr_issued'], __('customer gold obligation created')),
            $r(__('Held on G12 forward contracts (gold due at 12-month settlement)'), $l['forward_holding'], __('board: 100% holding, not stock allocation')),
        ];

        return [
            'heading' => __('4. Professional statement — Profit & Loss :from – :to', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [
                __('Total income') => $this->money($l['total_income']),
                __('Gross margin') => $this->money($l['gross_margin']),
                __('Distributions') => $this->money($l['total_distributions']),
                __('Operating expenses') => $this->money($l['total_expenses']),
                __('Net result') => $this->money($l['net_result']),
                __('Net result on income') => $this->pct($l['net_result'], $l['total_income']),
            ],
            'columns' => [__('Particulars'), __('Amount (₹)'), __('Basis')],
            'rows' => $rows,
        ];
    }

    protected function monthlySection(array $f): array
    {
        $byMonth = $f['monthly']->keyBy('month');
        $rows = [];
        $cursor = $f['from']->copy()->startOfMonth();
        $t = ['contracts' => 0, 'invested' => 0.0, 'installments' => 0.0, 'sales_metal' => 0.0, 'sales_profit' => 0.0, 'ic' => 0.0, 'cbc' => 0.0, 'gap_gross' => 0.0, 'billing' => 0.0, 'net' => 0.0];
        while ($cursor->lte($f['to'])) {
            $k = $cursor->format('Y-m');
            $m = $byMonth[$k] ?? null;
            $g = fn (string $key) => (float) ($m->{$key} ?? 0);
            $net = $g('invested') + $g('installments') + $g('sales_profit') - $g('ic') - $g('cbc') - $g('gap_gross') - $g('billing');
            $rows[] = [$cursor->format('M Y'), number_format($g('contracts')), $this->money($g('invested')), $this->money($g('installments')), $this->money($g('sales_metal')),
                $this->money($g('sales_profit')), $this->money($g('ic')), $this->money($g('cbc')), $this->money($g('gap_gross')), $this->money($g('billing')), $this->money($net)];
            foreach (array_keys($t) as $key) {
                $t[$key] += $key === 'net' ? $net : $g($key);
            }
            $cursor->addMonth();
        }
        $rows[] = [__('TOTAL'), number_format($t['contracts']), $this->money($t['invested']), $this->money($t['installments']), $this->money($t['sales_metal']), $this->money($t['sales_profit']),
            $this->money($t['ic']), $this->money($t['cbc']), $this->money($t['gap_gross']), $this->money($t['billing']), $this->money($t['net'])];

        return [
            'heading' => __('Month-by-month trend (:from – :to)', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'kv' => [__('Net inflow') => __('investment + installments + sales profit − IC − CBC − GAP (gross) − billing margin')],
            'columns' => [__('Month'), __('New contracts'), __('Investment (₹)'), __('Gold Saving installments (₹)'), __('Jewellery sales – metal (₹)'), __('Sales profit (₹)'),
                __('Sales Incentive (₹)'), __('CBC issued (₹)'), __('GAP gross (₹)'), __('Billing margin (₹)'), __('Net inflow (₹)')],
            'rows' => $rows,
        ];
    }

    protected function notesSection(array $f): array
    {
        $notes = [
            __('Source: the legacy Lord DB (lordicl), read live; nothing is cached or written.'),
            __('Period :from – :to; operating expenses and tax are the audit inputs saved on the Balance Sheet Report for FY :fy.', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to']), 'fy' => $f['fy']]),
            __('Scheme incoming = tbl_bond epinvalue (money received when the contract starts) + tbl_rdentry installments; gold-stock allocation = tbl_bond bvalue; CBC / IC provisions = the amounts set aside on the bond. Scheme rules come from the legacy plan master (tbl_planjewel) only.'),
            __('G12 Forward contractors is a 100% HOLDING (board 2026-10-21): the money stays with Lord and nothing is allotted as gold stock at billing (the Lord DB holds no invoice QR for any G12 bond); the gold falls due at the 12-month settlement, which is settled outside the system, and is shown as a memo line.'),
            __('Sales profit = making + wastage charges on gold / silver sales (line total − metal value at the day\'s rate); the CASH product on invoices is scheme money, never jewellery revenue.'),
            __('Sales Incentive = tbl_iccom by bill date (PAID / PASSED = paid). Promotional Incentive = tbl_cbc vouchers by the month they fall due (PASSED = issued). GAP = tbl_memberearning_level (comname GAP) by run date, gross before TDS. Billing Margin = tbl_reseller_com type 1.'),
            __('GAP TDS: 20% is deducted from every GAP payout before it is handed over (board 2026-10-21). The distribution is charged at gross; the TDS is shown as a memo line payable to government.'),
            __('Stock = tbl_stock as it stands now (pieces × milligrams per piece, cost = pieces × purchase price) for the dealer network only — LORD JEWELLER (#1) is never counted as stock portion; CASH stock rows are branch cash.'),
            __('Digi-gold withdrawals and gold-QR redemptions are not payout classes: the first repeats money already classified, the second is the customer exchanging their own gold.'),
            __('The professional statement treats gold-stock allocation and distributions as charges against income; GST, TDS and gold hand-overs are memo items.'),
        ];
        $kv = [];
        foreach ($notes as $i => $n) {
            $kv[($i + 1).'.'] = $n;
        }
        $kv[__('Generated')] = now()->toDayDateTimeString();

        return ['heading' => __('Notes & basis of preparation'), 'kv' => $kv];
    }
}
