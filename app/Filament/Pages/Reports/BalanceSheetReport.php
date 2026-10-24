<?php

namespace App\Filament\Pages\Reports;

use App\Support\Accounts\AccountsSource;
use App\Support\Accounts\LegacyAccountsSource;
use App\Support\Accounts\ManualLedger;
use App\Support\Accounts\MetalUnits;
use App\Support\Accounts\NextAccountsSource;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Balance Sheet Report (2026-10-14) — the auditor's workbook, laid out sheet for sheet
 * after Professional_Jeweller_Financial_Format.xlsx: Balance Sheet (T format), Profit &
 * Loss, Stock Summary, Gold / Silver Inventory Register, Gold Ledger, Silver Ledger,
 * Receivables, Payables, Notes.
 *
 * Figures come from either book (the legacy Lord DB or the new system — see
 * AccountsSource). The lines neither book tracks (capital, loans, fixed assets, bank,
 * expenses, tax) are keyed in by HQ on this page and kept per financial year
 * (ManualLedger). Closing stock is valued at purchase cost by default — the audit
 * basis — with the market value available as a switch.
 *
 * Dates: the Balance Sheet is drawn AS ON the To date; the Profit & Loss and the
 * ledgers cover From → To. Both books hold stock only as it stands today, so the stock
 * on an earlier To date is rolled back through the purchases and sales recorded after it.
 */
class BalanceSheetReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Balance Sheet Report';

    protected static ?string $title = 'Balance Sheet Report';

    public const STATEMENTS = [
        'workbook' => 'Full workbook (all sheets)',
        'balance_sheet' => 'Balance Sheet',
        'profit_loss' => 'Profit & Loss',
        'stock_summary' => 'Stock Summary',
        'inventory' => 'Gold / Silver Inventory Register',
        'gold_ledger' => 'Gold Ledger',
        'silver_ledger' => 'Silver Ledger',
        'receivables' => 'Receivables',
        'payables' => 'Payables',
    ];

    public const BASIS_COST = 'cost';

    public const BASIS_MARKET = 'market';

    /** Register / ledger rows kept on screen (Livewire carries every row in the page state); exports carry all. */
    public const SCREEN_ROWS = 500;

    public const PDF_ROWS = 100;

    protected bool $allRows = false;

    protected bool $pdfMode = false;

    /** Company-level document: HQ staff only (dealers and support never see it). */
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ! $u->isSupport() && ! $u->isDistributor();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function sourceOptions(): array
    {
        return [
            AccountsSource::LEGACY => __('Lord DB (lordicl)'),
            AccountsSource::NEXT => __('New system (lordicl-next)'),
        ];
    }

    public static function defaultSource(): string
    {
        return ! app()->runningUnitTests() && LegacyAccountsSource::available() ? AccountsSource::LEGACY : AccountsSource::NEXT;
    }

    protected function makeSource(?string $key): AccountsSource
    {
        return $key === AccountsSource::LEGACY ? new LegacyAccountsSource : new NextAccountsSource;
    }

    public function mount(): void
    {
        $to = now()->startOfDay();
        $this->form->fill([
            'statement' => 'workbook',
            'source' => static::defaultSource(),
            'basis' => self::BASIS_COST,
            'from' => MetalUnits::fyStart($to)->toDateString(),
            'to' => $to->toDateString(),
            'inputs' => ManualLedger::load(MetalUnits::fy($to)),
        ]);
    }

    public function form(Form $form): Form
    {
        $inputGroups = [];
        foreach (ManualLedger::FIELDS as $group => $fields) {
            $inputGroups[] = Forms\Components\Fieldset::make(__($group))->columns(4)->schema(
                collect($fields)->map(fn ($label, $field) => Forms\Components\TextInput::make('inputs.'.$field)
                    ->label(__($label))->numeric()->prefix('₹')->default(0)->minValue(-999999999999)->step('0.01'))->values()->all()
            );
        }

        return $form->schema([
            Forms\Components\Section::make()->columns(6)->schema([
                Forms\Components\Select::make('statement')->label(__('Statement'))
                    ->options(collect(self::STATEMENTS)->map(fn ($l) => __($l))->all())
                    ->required()->native(false)->selectablePlaceholder(false),
                Forms\Components\Select::make('source')->label(__('Data source'))
                    ->options(static::sourceOptions())->required()->native(false)->selectablePlaceholder(false)->live(),
                Forms\Components\DatePicker::make('from')->label(__('From date (P&L / ledgers)'))->required()->native(false),
                Forms\Components\DatePicker::make('to')->label(__('To date (Balance Sheet as on)'))->required()->native(false)->live(onBlur: true),
                Forms\Components\Select::make('branch_id')->label(__('Branch'))
                    ->options(function (Forms\Get $get) {
                        try {
                            return $this->makeSource($get('source'))->branchNames();
                        } catch (\Throwable) {
                            return [];
                        }
                    })
                    ->placeholder(__('All branches'))->searchable()->native(false),
                Forms\Components\Select::make('basis')->label(__('Closing stock valued at'))
                    ->options([self::BASIS_COST => __('Purchase cost (audit basis)'), self::BASIS_MARKET => __('Market value (live rate)')])
                    ->required()->native(false)->selectablePlaceholder(false),
            ]),
            Forms\Components\Section::make(__('Audit inputs — lines the system does not track'))
                ->description(__('Capital, loans, fixed assets, bank balance, expenses and tax are not recorded in either book. Enter them here; they are saved per financial year (April–March) of the To date and used by the Balance Sheet and the Profit & Loss.'))
                ->collapsible()->collapsed()
                ->headerActions([
                    Forms\Components\Actions\Action::make('saveInputs')->label(__('Save inputs'))->icon('heroicon-m-check')
                        ->action(fn () => $this->saveInputs()),
                    Forms\Components\Actions\Action::make('reloadInputs')->label(__('Reload saved'))->icon('heroicon-m-arrow-path')->color('gray')
                        ->action(fn () => $this->reloadInputs()),
                ])
                ->schema($inputGroups),
        ])->statePath('data');
    }

    protected function fy(): string
    {
        return MetalUnits::fy(Carbon::parse($this->form->getState()['to'] ?? now()));
    }

    public function saveInputs(): void
    {
        $fy = $this->fy();
        ManualLedger::save($fy, (array) ($this->form->getState()['inputs'] ?? []));
        Notification::make()->success()->title(__('Audit inputs saved for FY :fy', ['fy' => $fy]))->send();
    }

    public function reloadInputs(): void
    {
        $fy = $this->fy();
        $this->form->fill(array_merge($this->form->getState(), ['inputs' => ManualLedger::load($fy)]));
        Notification::make()->info()->title(__('Audit inputs loaded for FY :fy', ['fy' => $fy]))->send();
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
        $statement = (string) ($d['statement'] ?? 'workbook');
        $title = __(self::STATEMENTS[$statement] ?? 'Balance Sheet Report').' · '.$this->dmy($d['from']).' – '.$this->dmy($d['to']);

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'], true)
            ->loadView('pdf.sections', ['title' => $title, 'sections' => $sections, 'generatedAt' => now()->toDayDateTimeString()])
            ->setPaper('a4', $statement === 'balance_sheet' || $statement === 'profit_loss' ? 'portrait' : 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print ($output),
            $this->exportSlug().'-'.$statement.'-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** Cap a long table for the screen / PDF and say so in the block's key-values. */
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
        $basis = (string) ($d['basis'] ?? self::BASIS_COST);
        $statement = (string) ($d['statement'] ?? 'workbook');
        $inputs = array_map('floatval', (array) ($d['inputs'] ?? []));
        $source = $this->makeSource($d['source'] ?? null);

        $this->sections = [];
        $this->ran = true;

        try {
            $f = $this->compute($source, $from, $to, $branch, $basis, $inputs);
        } catch (\Throwable $e) {
            report($e);
            $this->sections[] = ['heading' => __('The :source could not be read', ['source' => $source->label()]),
                'kv' => [__('Error') => $e->getMessage()]];

            return;
        }

        $all = $statement === 'workbook';
        $want = fn (string $s) => $all || $statement === $s;

        if ($want('balance_sheet')) {
            $this->sections[] = $this->balanceSheetSection($f);
        }
        if ($want('profit_loss')) {
            $this->sections[] = $this->profitLossSection($f);
        }
        if ($want('stock_summary')) {
            $this->sections[] = $this->stockSummarySection($f);
        }
        if ($want('inventory')) {
            $this->sections[] = $this->inventorySection($f);
        }
        if ($want('gold_ledger')) {
            $this->sections[] = $this->ledgerSection($f, 'gold');
        }
        if ($want('silver_ledger')) {
            $this->sections[] = $this->ledgerSection($f, 'silver');
        }
        if ($want('receivables')) {
            $this->sections[] = $this->receivablesSection($f);
        }
        if ($want('payables')) {
            $this->sections[] = $this->payablesSection($f);
        }
        $this->sections[] = $this->notesSection($f);
    }

    // ---------------------------------------------------------------- figures

    /**
     * Every figure the sheets need, computed once. Keys:
     *   from, to, asOf, basis, source, branch, inputs, rates, stock (items), cat (metal/kind totals now),
     *   flows (metal → daily), stockAt (metal → [grams, cost] at From-1 and To), sales, purchases,
     *   purchaseMetal, invoices, receivables, dealerRecv, scheme, qrs, payables, cash, expenses, salaries,
     *   commissions, pl (lines), bs (lines).
     */
    protected function compute(AccountsSource $src, Carbon $from, Carbon $to, ?int $branch, string $basis, array $inputs): array
    {
        $m = fn (string $k) => (float) ($inputs[$k] ?? 0);
        $today = now()->startOfDay();
        $rates = $src->liveRates();

        // Stock as the book holds it now, totalled per metal / kind.
        $stock = $src->stockItems($branch);
        $cat = [];
        foreach (MetalUnits::METALS as $metal) {
            foreach (['jewellery', 'bullion'] as $kind) {
                $items = $stock->where('metal', $metal)->where('kind', $kind);
                $net = (float) $items->sum('net_wt');
                $cat[$metal][$kind] = [
                    'pieces' => (float) $items->sum('pieces'),
                    'gross' => (float) $items->sum('gross_wt'),
                    'stone' => (float) $items->sum('stone_wt'),
                    'net' => $net,
                    'cost' => (float) $items->sum('cost_value'),
                    'market' => round($net * ($rates[$metal] ?? 0), 2),
                ];
            }
        }

        // Daily movements from the From date up to today (or To when it lies ahead) — used to
        // roll today's stock back to the To date and to the day before From.
        $flows = [];
        $stockAt = [];
        foreach (MetalUnits::METALS as $metal) {
            $flows[$metal] = $src->metalFlows($metal, $from, $today->gt($to) ? $today : $to, $branch);
            $nowG = $cat[$metal]['jewellery']['net'] + $cat[$metal]['bullion']['net'];
            $nowCost = $cat[$metal]['jewellery']['cost'] + $cat[$metal]['bullion']['cost'];
            $at = function (Carbon $date) use ($flows, $metal, $nowG, $nowCost) {
                $g = $nowG;
                $v = $nowCost;
                foreach ($flows[$metal] as $day) {
                    if ($day->date > $date->toDateString()) {
                        $g -= $day->receipts_g - $day->issues_g;
                        $v -= $day->receipts_value - $day->issues_value;
                    }
                }

                return ['grams' => round($g, 4), 'cost' => round($v, 2)];
            };
            $stockAt[$metal] = ['opening' => $at($from->copy()->subDay()), 'closing' => $at($to), 'now' => ['grams' => $nowG, 'cost' => $nowCost]];
        }

        $sales = $src->salesByMetal($from, $to, $branch);
        $purchases = $src->purchases($to, $branch);
        $purchaseMetal = $src->purchaseLinesByMetal($from, $to, $branch)->keyBy('metal');
        $invoices = $src->invoiceTotals($from, $to, $branch);
        $receivables = $src->receivables($to, $branch);
        $dealerRecv = $src->dealerReceivables($to, $branch);
        $scheme = $src->schemeCollections($to, $branch);
        $qrs = $src->pendingQrs($to, $branch);
        $payables = $src->memberPayables($to, $branch);
        $cash = $src->cashInHand($branch);
        $expenses = $src->expenses($from, $to);
        $salaries = $src->salaries($from, $to);
        $commissions = $src->commissions($from, $to);

        $sum = fn (Collection $c, string $metal, ?string $kind, string $col) => (float) $c->where('metal', $metal)->when($kind, fn ($cc) => $cc->where('kind', $kind))->sum($col);
        $charges = $sum($sales, 'gold', null, 'charges') + $sum($sales, 'silver', null, 'charges');
        $schemeReceipts = (float) $sales->where('metal', 'cash')->sum('taxable');

        // Expense register rows → P&L lines by keyword; the rest lands in Other Expenses.
        $exp = ['salaries' => 0.0, 'rent' => 0.0, 'electricity' => 0.0, 'bank_charges' => 0.0, 'marketing' => 0.0, 'insurance' => 0.0, 'repairs' => 0.0, 'professional_fees' => 0.0, 'other_expenses' => 0.0];
        foreach ($expenses as $e) {
            $t = strtolower($e->type.' '.$e->title);
            $line = match (true) {
                str_contains($t, 'salar') || str_contains($t, 'wage') => 'salaries',
                str_contains($t, 'rent') => 'rent',
                str_contains($t, 'electric') || str_contains($t, 'eb ') || str_contains($t, 'power') => 'electricity',
                str_contains($t, 'bank') => 'bank_charges',
                str_contains($t, 'market') || str_contains($t, 'advert') || str_contains($t, 'promo') => 'marketing',
                str_contains($t, 'insur') => 'insurance',
                str_contains($t, 'repair') || str_contains($t, 'mainten') => 'repairs',
                str_contains($t, 'audit') || str_contains($t, 'profession') || str_contains($t, 'legal') || str_contains($t, 'ca fee') => 'professional_fees',
                default => 'other_expenses',
            };
            $exp[$line] += (float) $e->amount;
        }

        // ---- Profit & Loss
        $pl = [];
        $pl['gold_jewellery_sales'] = $sum($sales, 'gold', 'jewellery', 'metal_value');
        $pl['silver_jewellery_sales'] = $sum($sales, 'silver', 'jewellery', 'metal_value');
        $pl['diamond_sales'] = 0.0;
        $pl['bullion_sales'] = $sum($sales, 'gold', 'bullion', 'metal_value') + $sum($sales, 'silver', 'bullion', 'metal_value');
        $pl['old_gold_sales'] = $m('old_gold_sales');
        $pl['making_income'] = $charges;
        $pl['other_revenue'] = $m('other_revenue');
        $pl['total_revenue'] = array_sum(array_intersect_key($pl, array_flip(['gold_jewellery_sales', 'silver_jewellery_sales', 'diamond_sales', 'bullion_sales', 'old_gold_sales', 'making_income', 'other_revenue'])));

        $pl['opening_stock'] = $stockAt['gold']['opening']['cost'] + $stockAt['silver']['opening']['cost'];
        $pl['purchases_gold'] = (float) ($purchaseMetal['gold']->value ?? 0);
        $pl['purchases_silver'] = (float) ($purchaseMetal['silver']->value ?? 0);
        $pl['purchases_diamond'] = $m('diamond_purchases');
        $pl['manufacturing_charges'] = $m('manufacturing_charges');
        $pl['freight_inward'] = $m('freight_inward');
        $pl['closing_stock'] = $stockAt['gold']['closing']['cost'] + $stockAt['silver']['closing']['cost'];
        $pl['gross_cost'] = $pl['opening_stock'] + $pl['purchases_gold'] + $pl['purchases_silver'] + $pl['purchases_diamond']
            + $pl['manufacturing_charges'] + $pl['freight_inward'] - $pl['closing_stock'];
        $pl['gross_profit'] = $pl['total_revenue'] - $pl['gross_cost'];

        $pl['salaries'] = $salaries + $exp['salaries'] + $m('salaries_extra');
        $pl['rent'] = $exp['rent'] + $m('rent');
        $pl['electricity'] = $exp['electricity'] + $m('electricity');
        $pl['bank_charges'] = $exp['bank_charges'] + $m('bank_charges');
        $pl['marketing'] = $exp['marketing'] + $m('marketing');
        $pl['insurance'] = $exp['insurance'] + $m('insurance');
        $pl['repairs'] = $exp['repairs'] + $m('repairs');
        $pl['depreciation'] = $m('depreciation');
        $pl['professional_fees'] = $exp['professional_fees'] + $m('professional_fees');
        $pl['commissions'] = $commissions;
        $pl['other_expenses'] = $exp['other_expenses'] + $m('other_expenses');
        $pl['total_opex'] = $pl['salaries'] + $pl['rent'] + $pl['electricity'] + $pl['bank_charges'] + $pl['marketing'] + $pl['insurance']
            + $pl['repairs'] + $pl['depreciation'] + $pl['professional_fees'] + (float) $commissions + $pl['other_expenses'];
        $pl['pbt'] = $pl['gross_profit'] - $pl['total_opex'];
        $pl['income_tax'] = $m('income_tax');
        $pl['net_profit'] = $pl['pbt'] - $pl['income_tax'];

        // ---- Balance Sheet as on To
        $outputGst = $invoices['cgst'] + $invoices['sgst'];
        $inputGst = (float) $purchases->filter(fn ($p) => $p->date->between($from, $to))->sum('gst');
        $stockValue = fn (string $metal, string $kind) => $basis === self::BASIS_MARKET ? $cat[$metal][$kind]['market'] : $cat[$metal][$kind]['cost'];
        // Stock on the To date = today's book rolled back; the split by kind follows today's proportions.
        $rollback = function (string $metal) use ($stockAt, $cat, $basis) {
            $now = $stockAt[$metal]['now']['cost'];
            if ($basis === self::BASIS_MARKET) {
                $nowG = $stockAt[$metal]['now']['grams'];

                return $nowG > 0 ? $stockAt[$metal]['closing']['grams'] / $nowG : 1.0;
            }

            return $now > 0 ? $stockAt[$metal]['closing']['cost'] / $now : 1.0;
        };

        $bs = [];
        $bs['opening_capital'] = $m('opening_capital');
        $bs['net_profit'] = $pl['net_profit'];
        $bs['drawings'] = $m('drawings');
        $bs['net_capital'] = $bs['opening_capital'] + $bs['net_profit'] - $bs['drawings'];
        $bs['bank_gold_loan'] = $m('bank_gold_loan');
        $bs['working_capital_loan'] = $m('working_capital_loan');
        $bs['directors_loan'] = $m('directors_loan');
        $bs['sundry_creditors'] = (float) $purchases->sum('balance');
        $bs['customer_advances'] = $qrs['value'];
        $bs['scheme_collections'] = $scheme['contract_value'] + $scheme['installments'];
        $bs['gst_payable'] = max(0.0, round($outputGst - $inputGst, 2));
        $bs['other_payables'] = array_sum($payables);
        $bs['provisions_payable'] = $m('provisions_payable');
        $bs['total_liabilities'] = $bs['net_capital'] + $bs['bank_gold_loan'] + $bs['working_capital_loan'] + $bs['directors_loan']
            + $bs['sundry_creditors'] + $bs['customer_advances'] + $bs['scheme_collections'] + $bs['gst_payable'] + $bs['other_payables'] + $bs['provisions_payable'];

        $bs['land_building'] = $m('land_building');
        $bs['furniture'] = $m('furniture');
        $bs['showroom_interior'] = $m('showroom_interior');
        $bs['computers'] = $m('computers');
        $bs['vehicles'] = $m('vehicles');
        $bs['accumulated_depreciation'] = $m('accumulated_depreciation');
        $bs['net_fixed_assets'] = $bs['land_building'] + $bs['furniture'] + $bs['showroom_interior'] + $bs['computers'] + $bs['vehicles'] - $bs['accumulated_depreciation'];
        $bs['gold_jewellery_stock'] = round($stockValue('gold', 'jewellery') * $rollback('gold'), 2);
        $bs['gold_bullion'] = round($stockValue('gold', 'bullion') * $rollback('gold'), 2);
        $bs['silver_jewellery_stock'] = round($stockValue('silver', 'jewellery') * $rollback('silver'), 2);
        $bs['silver_bullion'] = round($stockValue('silver', 'bullion') * $rollback('silver'), 2);
        $bs['diamond_stock'] = $m('diamond_stock');
        $bs['precious_stones'] = $m('precious_stones');
        $bs['wip_stock'] = $m('wip_stock');
        $bs['old_gold_stock'] = $m('old_gold_stock');
        $bs['customer_receivables'] = (float) $receivables->sum('balance');
        $bs['dealer_receivables'] = $dealerRecv;
        $bs['cash_in_hand'] = $cash;
        $bs['bank_balance'] = $m('bank_balance');
        $bs['gst_input_credit'] = max(0.0, round($inputGst - $outputGst, 2));
        $bs['other_current_assets'] = $m('other_current_assets');
        $bs['total_assets'] = $bs['net_fixed_assets'] + $bs['gold_jewellery_stock'] + $bs['gold_bullion'] + $bs['silver_jewellery_stock'] + $bs['silver_bullion']
            + $bs['diamond_stock'] + $bs['precious_stones'] + $bs['wip_stock'] + $bs['old_gold_stock'] + $bs['customer_receivables'] + $bs['dealer_receivables']
            + $bs['cash_in_hand'] + $bs['bank_balance'] + $bs['gst_input_credit'] + $bs['other_current_assets'];

        return compact('from', 'to', 'basis', 'branch', 'inputs', 'rates', 'stock', 'cat', 'flows', 'stockAt', 'sales', 'purchases', 'purchaseMetal',
            'invoices', 'receivables', 'dealerRecv', 'scheme', 'qrs', 'payables', 'cash', 'expenses', 'salaries', 'commissions', 'pl', 'bs')
            + ['source' => $src, 'outputGst' => $outputGst, 'inputGst' => $inputGst, 'schemeReceipts' => $schemeReceipts, 'today' => $today];
    }

    // ---------------------------------------------------------------- sheets

    /** Two columns of [label, value] laid side by side, as the workbook does. */
    protected function tRows(array $left, array $right): array
    {
        $rows = [];
        for ($i = 0, $n = max(count($left), count($right)); $i < $n; $i++) {
            $l = $left[$i] ?? ['', null];
            $r = $right[$i] ?? ['', null];
            $rows[] = [$l[0], $this->amount($l[1]), $r[0], $this->amount($r[1])];
        }

        return $rows;
    }

    protected function amount($v): string
    {
        return $v === null ? '' : $this->money($v);
    }

    protected function grams(float $g): string
    {
        return number_format($g, 3).' g';
    }

    protected function balanceSheetSection(array $f): array
    {
        $bs = $f['bs'];
        $left = [
            [__('Capital Account'), null],
            [__('Opening Capital'), $bs['opening_capital']],
            [__('Add: Net Profit'), $bs['net_profit']],
            [__('Less: Drawings'), $bs['drawings']],
            [__('Net Capital'), $bs['net_capital']],
            [__('Secured Loans'), null],
            [__('Bank / Gold Loan'), $bs['bank_gold_loan']],
            [__('Working Capital Loan'), $bs['working_capital_loan']],
            [__('Unsecured Loans'), null],
            [__('Directors / Partners Loan'), $bs['directors_loan']],
            [__('Current Liabilities'), null],
            [__('Sundry Creditors'), $bs['sundry_creditors']],
            [__('Customer Advances'), $bs['customer_advances']],
            [__('Gold Saving Scheme / Customer Collections'), $bs['scheme_collections']],
            [__('GST Payable'), $bs['gst_payable']],
            [__('Other Payables'), $bs['other_payables']],
            [__('Provision / Expenses Payable'), $bs['provisions_payable']],
            [__('Total Liabilities'), $bs['total_liabilities']],
        ];
        $right = [
            [__('Fixed Assets'), null],
            [__('Land & Building'), $bs['land_building']],
            [__('Furniture & Fixtures'), $bs['furniture']],
            [__('Showroom Interior'), $bs['showroom_interior']],
            [__('Computers & Equipment'), $bs['computers']],
            [__('Vehicles'), $bs['vehicles']],
            [__('Accumulated Depreciation'), -$bs['accumulated_depreciation']],
            [__('Net Fixed Assets'), $bs['net_fixed_assets']],
            [__('Current Assets'), null],
            [__('Gold Jewellery Stock'), $bs['gold_jewellery_stock']],
            [__('Gold Bullion / Bars'), $bs['gold_bullion']],
            [__('Silver Jewellery Stock'), $bs['silver_jewellery_stock']],
            [__('Silver Bullion'), $bs['silver_bullion']],
            [__('Diamond Jewellery Stock'), $bs['diamond_stock']],
            [__('Precious Stones'), $bs['precious_stones']],
            [__('WIP / Manufacturing Stock'), $bs['wip_stock']],
            [__('Old Gold Stock'), $bs['old_gold_stock']],
            [__('Customer Receivables'), $bs['customer_receivables']],
            [__('Dealer / Branch Receivables'), $bs['dealer_receivables']],
            [__('Cash in Hand'), $bs['cash_in_hand']],
            [__('Bank Balance'), $bs['bank_balance']],
            [__('GST Input Credit'), $bs['gst_input_credit']],
            [__('Other Current Assets'), $bs['other_current_assets']],
            [__('Total Assets'), $bs['total_assets']],
        ];
        $diff = round($bs['total_assets'] - $bs['total_liabilities'], 2);

        return [
            'heading' => __('Balance Sheet as on :date', ['date' => $this->dmy($f['to'])]),
            'page_break' => true,
            'kv' => [
                __('Source') => $f['source']->label(),
                __('Closing stock valued at') => $f['basis'] === self::BASIS_MARKET ? __('Market value (live rate)') : __('Purchase cost'),
                __('Total Liabilities') => $this->money($bs['total_liabilities']),
                __('Total Assets') => $this->money($bs['total_assets']),
                __('Difference (Assets − Liabilities)') => $this->money($diff),
                __('Net profit taken from P&L') => $this->dmy($f['from']).' – '.$this->dmy($f['to']),
            ],
            'columns' => [__('Particulars'), __('Amount (₹)'), __('Particulars'), __('Amount (₹)')],
            'rows' => $this->tRows($left, $right),
        ];
    }

    protected function profitLossSection(array $f): array
    {
        $pl = $f['pl'];
        $left = [
            [__('Sales Revenue'), null],
            [__('Gold Jewellery Sales'), $pl['gold_jewellery_sales']],
            [__('Silver Jewellery Sales'), $pl['silver_jewellery_sales']],
            [__('Diamond Jewellery Sales'), $pl['diamond_sales']],
            [__('Bullion Sales'), $pl['bullion_sales']],
            [__('Old Gold / Exchange Sales'), $pl['old_gold_sales']],
            [__('Making Charges Income'), $pl['making_income']],
            [__('Other Operating Revenue'), $pl['other_revenue']],
            [__('Total Revenue'), $pl['total_revenue']],
            [__('Gross Profit'), $pl['gross_profit']],
        ];
        $right = [
            [__('Cost of Sales'), null],
            [__('Opening Stock (gold + silver)'), $pl['opening_stock']],
            [__('Purchases – Gold'), $pl['purchases_gold']],
            [__('Purchases – Silver'), $pl['purchases_silver']],
            [__('Purchases – Diamond / Stones'), $pl['purchases_diamond']],
            [__('Goldsmith / Manufacturing Charges'), $pl['manufacturing_charges']],
            [__('Freight / Carriage Inward'), $pl['freight_inward']],
            [__('Closing Stock (gold + silver)'), -$pl['closing_stock']],
            [__('Gross Cost of Sales'), $pl['gross_cost']],
            [__('Operating Expenses'), null],
            [__('Employee Salaries'), $pl['salaries']],
            [__('Rent & Maintenance'), $pl['rent']],
            [__('Electricity'), $pl['electricity']],
            [__('Bank Charges'), $pl['bank_charges']],
            [__('Marketing / Advertisement'), $pl['marketing']],
            [__('Insurance'), $pl['insurance']],
            [__('Repairs & Maintenance'), $pl['repairs']],
            [__('Depreciation'), $pl['depreciation']],
            [__('Professional / Audit Fees'), $pl['professional_fees']],
            [__('Distributor Commissions'), $pl['commissions']],
            [__('Other Expenses'), $pl['other_expenses']],
            [__('Total Operating Expenses'), $pl['total_opex']],
            [__('Profit Before Tax'), $pl['pbt']],
            [__('Income Tax / Current Tax'), $pl['income_tax']],
            [__('Net Profit'), $pl['net_profit']],
        ];
        $rows = $this->tRows($left, $right);
        if ($pl['commissions'] === null) {
            foreach ($rows as &$row) {
                if ($row[2] === __('Distributor Commissions')) {
                    $row[3] = __('not in this book');
                }
            }
            unset($row);
        }

        return [
            'heading' => __('Profit & Loss :from – :to', ['from' => $this->dmy($f['from']), 'to' => $this->dmy($f['to'])]),
            'page_break' => true,
            'kv' => [
                __('Invoices in the period') => (string) $f['invoices']['count'],
                __('Taxable turnover (invoices)') => $this->money($f['invoices']['taxable']),
                __('Output GST (CGST + SGST)') => $this->money($f['outputGst']),
                __('Input GST (purchases)') => $this->money($f['inputGst']),
                __('Scheme / contract receipts (not revenue)') => $this->money($f['schemeReceipts']),
                __('Gold sold') => $this->grams((float) $f['sales']->where('metal', 'gold')->sum('grams')),
                __('Silver sold') => $this->grams((float) $f['sales']->where('metal', 'silver')->sum('grams')),
                __('Payroll in the period') => $this->money($f['salaries']),
            ],
            'columns' => [__('Particulars'), __('Amount (₹)'), __('Particulars'), __('Amount (₹)')],
            'rows' => $rows,
        ];
    }

    protected function stockSummarySection(array $f): array
    {
        $cat = $f['cat'];
        $market = $f['basis'] === self::BASIS_MARKET;
        $m = fn (string $k) => (float) ($f['inputs'][$k] ?? 0);
        $line = function (string $label, array $c) use ($market) {
            $metalValue = $market ? $c['market'] : $c['cost'];

            return [$label, $this->grams($c['gross']), $this->grams($c['stone']), $this->grams($c['net']), $this->money($metalValue), $this->money(0), $this->money(0), $this->money($metalValue)];
        };
        $manual = fn (string $label, float $v) => [$label, '—', '—', '—', '—', '—', $this->money($v), $this->money($v)];

        $rows = [
            $line(__('Gold Jewellery'), $cat['gold']['jewellery']),
            $line(__('Gold Bullion'), $cat['gold']['bullion']),
            $line(__('Silver Jewellery'), $cat['silver']['jewellery']),
            $line(__('Silver Bullion'), $cat['silver']['bullion']),
            $manual(__('Diamond Jewellery'), $m('diamond_stock')),
            $manual(__('Precious Stones'), $m('precious_stones')),
            $manual(__('Old Gold'), $m('old_gold_stock')),
            $manual(__('WIP / Manufacturing'), $m('wip_stock')),
        ];
        $metalTotal = 0.0;
        $gross = $stone = $net = 0.0;
        foreach (MetalUnits::METALS as $metal) {
            foreach (['jewellery', 'bullion'] as $kind) {
                $gross += $cat[$metal][$kind]['gross'];
                $stone += $cat[$metal][$kind]['stone'];
                $net += $cat[$metal][$kind]['net'];
                $metalTotal += $market ? $cat[$metal][$kind]['market'] : $cat[$metal][$kind]['cost'];
            }
        }
        $manualTotal = $m('diamond_stock') + $m('precious_stones') + $m('old_gold_stock') + $m('wip_stock');
        $rows[] = [__('TOTAL STOCK'), $this->grams($gross), $this->grams($stone), $this->grams($net), $this->money($metalTotal), $this->money(0), $this->money($manualTotal), $this->money($metalTotal + $manualTotal)];

        return [
            'heading' => __('Jewellery Stock Summary (as the book stands on :date)', ['date' => $this->dmy($f['today'])]),
            'page_break' => true,
            'kv' => [
                __('Valuation') => $market ? __('Net weight × live rate') : __('Pieces × purchase cost'),
                __('Live gold rate / g') => $this->money($f['rates']['gold']),
                __('Live silver rate / g') => $this->money($f['rates']['silver']),
                __('Gold at cost') => $this->money($cat['gold']['jewellery']['cost'] + $cat['gold']['bullion']['cost']),
                __('Gold at market') => $this->money($cat['gold']['jewellery']['market'] + $cat['gold']['bullion']['market']),
                __('Silver at cost') => $this->money($cat['silver']['jewellery']['cost'] + $cat['silver']['bullion']['cost']),
                __('Silver at market') => $this->money($cat['silver']['jewellery']['market'] + $cat['silver']['bullion']['market']),
                __('Stock lines') => (string) $f['stock']->count(),
            ],
            'columns' => [__('Category'), __('Gross Wt (g)'), __('Stone Wt (g)'), __('Net Wt (g)'), __('Metal Value (₹)'), __('Making / Charges (₹)'), __('Stone Value (₹)'), __('Total Value (₹)')],
            'rows' => $rows,
        ];
    }

    protected function inventorySection(array $f): array
    {
        $date = $this->dmy($f['today']);
        $rows = $f['stock']->map(fn ($s) => [
            $date, ucfirst($s->metal).' · '.ucfirst($s->kind), $s->item, $s->description, $s->purity,
            $this->grams($s->gross_wt), $this->grams($s->stone_wt), $this->grams($s->net_wt), $this->money($s->cost_rate),
            $this->money($s->cost_value), $this->money(0), $this->money(0), $this->money($s->cost_value), $s->location,
            number_format($s->pieces, 2).' × '.number_format($s->gram_each, 3).' g',
        ])->all();
        $kv = [
            __('Items') => (string) count($rows),
            __('Pieces') => number_format((float) $f['stock']->sum('pieces'), 2),
            __('Net weight') => $this->grams((float) $f['stock']->sum('net_wt')),
            __('Value at cost') => $this->money((float) $f['stock']->sum('cost_value')),
        ];

        return [
            'heading' => __('Gold / Silver Inventory Register'),
            'page_break' => true,
            'kv' => $kv,
            'columns' => [__('Date'), __('Item Type'), __('Item / SKU'), __('Description'), __('Purity'), __('Gross Wt (g)'), __('Stone Wt (g)'), __('Net Wt (g)'),
                __('Rate / g (₹)'), __('Metal Value (₹)'), __('Making / Charges (₹)'), __('Stone Value (₹)'), __('Total Value (₹)'), __('Location'), __('Remarks')],
            'rows' => $this->capRows($kv, $rows),
        ];
    }

    protected function ledgerSection(array $f, string $metal): array
    {
        $opening = $f['stockAt'][$metal]['opening'];
        $closing = $f['stockAt'][$metal]['closing'];
        $live = (float) ($f['rates'][$metal] ?? 0);
        $balance = $opening['grams'];
        $rows = [[$this->dmy($f['from']), __('Opening balance'), $this->grams($balance), $this->grams(0), $this->grams(0), $this->grams($balance),
            $this->money($live), $this->money($balance * $live), __('Rolled back from today\'s stock')]];
        foreach ($f['flows'][$metal] as $day) {
            if ($day->date > $f['to']->toDateString()) {
                break;
            }
            $open = $balance;
            $balance = round($balance + $day->receipts_g - $day->issues_g, 4);
            $moved = $day->receipts_g + $day->issues_g;
            $rate = $moved > 0 ? ($day->receipts_value + $day->issues_value) / $moved : $live;
            $what = $day->receipts_g > 0 && $day->issues_g > 0 ? __('Purchases + Sales') : ($day->receipts_g > 0 ? __('Purchases') : __('Sales'));
            $rows[] = [$this->dmy($day->date), $what, $this->grams($open), $this->grams($day->receipts_g), $this->grams($day->issues_g),
                $this->grams($balance), $this->money($rate), $this->money($balance * $rate), $what === __('Sales') ? __('Sales invoices') : __('Purchase bills')];
        }
        $rows[] = [$this->dmy($f['to']), __('Closing balance'), $this->grams($balance), $this->grams(0), $this->grams(0), $this->grams($balance),
            $this->money($live), $this->money($balance * $live), __('Cost on the book: :v', ['v' => $this->money($closing['cost'])])];
        $kv = [
            __('Opening weight') => $this->grams($opening['grams']),
            __('Receipts') => $this->grams((float) $f['flows'][$metal]->filter(fn ($d) => $d->date <= $f['to']->toDateString())->sum('receipts_g')),
            __('Issues / sales') => $this->grams((float) $f['flows'][$metal]->filter(fn ($d) => $d->date <= $f['to']->toDateString())->sum('issues_g')),
            __('Closing weight') => $this->grams($closing['grams']),
            __('Closing at cost') => $this->money($closing['cost']),
            __('Closing at live rate') => $this->money($closing['grams'] * $live),
        ];

        return [
            'heading' => $metal === 'gold' ? __('Gold Metal Account / Weight Ledger') : __('Silver Metal Account / Weight Ledger'),
            'page_break' => true,
            'kv' => $kv,
            'columns' => [__('Date'), __('Particulars'), __('Opening Wt (g)'), __('Receipts (g)'), __('Issues / Sales (g)'), __('Closing Wt (g)'), __('Rate / g (₹)'), __('Value (₹)'), __('Reference')],
            'rows' => $this->capRows($kv, $rows),
        ];
    }

    protected function receivablesSection(array $f): array
    {
        $rows = $f['receivables']->map(fn ($r) => [
            $this->dmy($r->date), $r->party, $r->invoice_no, $r->description, $this->money($r->debit), $this->money($r->credit),
            $this->money($r->balance), $r->due ? $this->dmy($r->due) : '—', $r->status,
        ])->all();
        $kv = [
            __('Open invoices') => (string) count($rows),
            __('Customer receivables') => $this->money($f['bs']['customer_receivables']),
            __('Dealer / branch receivables') => $this->money($f['bs']['dealer_receivables']),
            __('Invoices in the period') => (string) $f['invoices']['count'],
            __('Billed in the period') => $this->money($f['invoices']['grand']),
            __('Received in the period') => $this->money($f['invoices']['received']),
        ];

        return [
            'heading' => __('Customer / Dealer Receivables as on :date', ['date' => $this->dmy($f['to'])]),
            'page_break' => true,
            'kv' => $kv,
            'columns' => [__('Date'), __('Customer / Dealer'), __('Invoice No'), __('Description'), __('Debit (₹)'), __('Credit / Receipt (₹)'), __('Balance (₹)'), __('Due Date'), __('Status')],
            'rows' => $this->capRows($kv, $rows),
        ];
    }

    protected function payablesSection(array $f): array
    {
        $rows = $f['purchases']->map(fn ($p) => [
            $this->dmy($p->date), $p->supplier, $p->bill_no, $p->description, $this->money($p->paid), $this->money($p->grand),
            $this->money($p->balance), '—', $p->status,
        ])->all();
        $kv = [
            __('Supplier bills') => (string) count($rows),
            __('Purchased (incl. GST)') => $this->money((float) $f['purchases']->sum('grand')),
            __('Paid') => $this->money((float) $f['purchases']->sum('paid')),
            __('Sundry creditors') => $this->money($f['bs']['sundry_creditors']),
        ];
        foreach ($f['payables'] as $label => $v) {
            $kv[__($label)] = $this->money($v);
        }

        return [
            'heading' => __('Supplier / Goldsmith Payables as on :date', ['date' => $this->dmy($f['to'])]),
            'page_break' => true,
            'kv' => $kv,
            'columns' => [__('Date'), __('Supplier / Goldsmith'), __('Bill No'), __('Description'), __('Debit / Payment (₹)'), __('Credit / Purchase (₹)'), __('Balance (₹)'), __('Due Date'), __('Status')],
            'rows' => $this->capRows($kv, $rows),
        ];
    }

    protected function notesSection(array $f): array
    {
        $notes = [
            __('Format: Professional Jeweller Financial Format (Balance Sheet, Profit & Loss, Stock Summary, Inventory Register, Gold / Silver Ledgers, Receivables, Payables).'),
            __('Balance Sheet as on :to; Profit & Loss and ledgers for :from – :to; financial year :fy (April–March) for the manual inputs.', ['to' => $this->dmy($f['to']), 'from' => $this->dmy($f['from']), 'fy' => MetalUnits::fy($f['to'])]),
            __('Closing stock is valued at :basis. Opening stock = closing stock rolled back through the purchases and sales recorded in the period; cost of metal sold = metal value at the day\'s sale rate.', ['basis' => $f['basis'] === self::BASIS_MARKET ? __('net weight × live rate') : __('purchase cost (pieces × cost per piece)')]),
            __('Weight-denominated coins / bars are classed as Bullion; every other item as Jewellery. Making charges and stone values are not carried on stock at cost.'),
            __('GST: output tax = CGST + SGST on sales and redemption invoices in the period; input tax = GST on purchase bills in the period; the net appears as GST Payable or GST Input Credit.'),
            __('Lines marked as audit inputs (capital, drawings, loans, fixed assets, depreciation, bank, diamond / stone / old-gold / WIP stock, expenses, tax) are entered by HQ and are not system figures. Opening Capital must be entered for the two sides to agree; the difference line shows what is still unrecorded.'),
            __('Customer advances / gold-saving-scheme collections are shown as liabilities; their legal and accounting classification must be confirmed with the auditor (workbook note).'),
        ];
        foreach ($f['source']->notes() as $n) {
            $notes[] = $n;
        }
        $kv = [];
        foreach ($notes as $i => $n) {
            $kv[($i + 1).'.'] = $n;
        }
        $kv[__('Scheme contracts running')] = number_format($f['scheme']['contracts']).' · '.$this->money($f['scheme']['contract_value']).' + '.__('installments').' '.$this->money($f['scheme']['installments']);
        $kv[__('Undelivered gold QRs')] = number_format($f['qrs']['count']).' · '.$this->grams($f['qrs']['grams']).' · '.$this->money($f['qrs']['value']);
        $kv[__('Generated')] = now()->toDayDateTimeString();

        return ['heading' => __('Notes & basis of preparation'), 'page_break' => true, 'kv' => $kv];
    }
}
