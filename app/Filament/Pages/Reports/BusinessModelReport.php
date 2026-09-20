<?php

namespace App\Filament\Pages\Reports;

use App\Models\Branch;
use App\Models\LiveRate;
use App\Models\Plan;
use App\Services\Qr\GoldQrPricing;
use App\Support\BusinessModel\ContractStatus;
use App\Support\BusinessModel\LegacyLordSource;
use App\Support\BusinessModel\NextSystemSource;
use App\Support\BusinessModel\ReportSource;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Business Model Report (board 2026-09-16) — the "Lord structure" in one document:
 * every scheme laid out down the dealership ladder, each with its scheme facts, its
 * ACTIVE and its CLOSED contracts (bonds) in separate blocks with aggregate amounts
 * and settlement figures, then the customer-level G11 PLUS1 savings scheme with
 * installments received and the 100 mg gold allocations they produced.
 *
 * Board labels (fixed wording):
 *   Investment amount            → "Started Amount"     (contract amount at billing)
 *   Allocation amount            → "Spot Gold Stock"    (gold QR allocated at billing / per installment)
 *   Settlement at 12 months      → "1-year Gold Stock"  (the plan's 12-month settlement QR)
 *   Settlement at 24 months      → "2nd-year Gold Stock" (settlement paid when the contract expires)
 *
 * Data source (board follow-up, same day): the report reads the LIVE legacy Lord DB
 * (`lordicl`.tbl_bond / tbl_rdentry / tbl_digi_queue) by default — that is the book
 * the board knows — and can be switched to the new system's own tables. Scheme facts
 * (limits, allocation split, settlement rules) always come from the new plan master;
 * nothing in either system is written.
 *
 * Filter = two dates. Contracts are picked by their START date inside the window;
 * every figure on a picked contract (installments, allocations, settlements) is read
 * as on the TO date. Live data straight from the database — nothing is cached.
 */
class BusinessModelReport extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Business Model Report';

    protected static ?string $title = 'Business Model Report';

    public const L_STARTED = 'Started Amount';

    public const L_SPOT = 'Spot Gold Stock';

    public const L_YEAR1 = '1-year Gold Stock';

    public const L_YEAR2 = '2nd-year Gold Stock';

    /** Plans.hid → report section, in board order. */
    public const DEALERSHIP_HIDS = [1, 2, 3, 4];          // Regional → Zonal → District → Taluka

    public const RETAIL_HIDS = [7, 5, 6];                 // Wholesaler → Retailer → Sub Dealer

    public const SAVINGS_PLAN_CODE = 'P209';              // G11 Gold Savings Plan PLUS1

    public const MODE_FULL = 'full';

    public const MODE_LITE = 'lite';

    /** Board 2026-09-17: dealer sections from the dealer LOGINS (branch users), G12 / G11 from closed bonds, no date range. */
    public const MODE_DEALERS = 'dealers';

    /**
     * Simplified report (board 2026-09-17): the board's own template — dealer schemes list
     * every distributor who JOINED in the period ('joined'), G12 / G11 list CLOSED contracts only — fixed facts per scheme and one table of Distributor / Start / End /
     * Started Amount / Delivery Amount. Delivery = the stated gold-stock share of the
     * Started Amount for dealers; paid total + bonus months for the G11 savings plans.
     */
    public const LITE = [
        ['title' => 'Dealers (Offline-Dealers)', 'gst' => true, 'items' => [
            ['no' => 1, 'label' => 'Zonal', 'joined' => true, 'level' => 'zonal', 'code' => 'P207', 'share' => 0.80,
                'facts' => ['Gold Stock' => '80%', 'Interior Works Allocation' => '10%', 'Refundable Deposit' => '10%', 'Sales Limit (Wholesale Price)' => 'Unlimited']],
            ['no' => 2, 'label' => 'District', 'joined' => true, 'level' => 'district', 'code' => 'P204', 'share' => 0.80,
                'facts' => ['Gold Stock' => '80%', 'Interior Works Allocation' => '10%', 'Refundable Deposit' => '10%', 'Sales Limit (Wholesale Price)' => 'Unlimited']],
            ['no' => 3, 'label' => 'Taluk', 'joined' => true, 'level' => 'taluk', 'code' => 'P203', 'share' => 0.80,
                'facts' => ['Gold Stock' => '80%', 'Interior Works Allocation' => '10%', 'Refundable Deposit' => '10%', 'Sales Limit (Wholesale Price)' => 'Unlimited']],
        ]],
        ['title' => 'Dealers (Online-Dealers)', 'gst' => true, 'items' => [
            ['no' => 1, 'label' => 'Wholesaler', 'joined' => true, 'level' => 'wholesaler', 'code' => 'P205', 'share' => 0.70,
                'facts' => ['Gold Stock' => '70%', 'Business Value Share' => '30%', 'Sales Limit (Wholesale Price)' => '30L / Month']],
            ['no' => 2, 'label' => 'Retailer', 'joined' => true, 'level' => 'reseller', 'code' => 'P201', 'share' => 0.50,
                'facts' => ['Gold Stock' => '50%', 'Business Value Share' => '50%', 'Sales Limit (Wholesale Price)' => '15L / Month']],
            ['no' => 3, 'label' => 'G12 Forward Contract', 'code' => 'P202', 'share' => 1.00,
                'facts' => ['Gold Stock' => '100%', 'Business Value Share' => '100%', 'Sales Limit (Wholesale Price)' => '45L / Month']],
        ]],
        ['title' => 'Customer (Gold-Saving)', 'items' => [
            ['no' => 1, 'label' => 'G11 plus 1', 'code' => 'P209', 'bonus' => 1,
                'facts' => ['Payable Month' => '11 Month', 'Bonus Value' => '1 Month Due', 'Security' => '100mg Gold Coin X 11 Month']],
            ['no' => 2, 'label' => 'G11 plus 2', 'code' => 'P208', 'bonus' => 2,
                'facts' => ['Payable Month' => '11 Month', 'Bonus Value' => '2 Month Due', 'Security' => '100mg Gold Coin X 1 Month']],
            ['no' => 3, 'label' => 'G11 plus 3', 'code' => 'P200', 'bonus' => 3,
                'facts' => ['Payable Month' => '11 Month', 'Bonus Value' => '3 Month Due', 'Security' => 'NONE']],
        ]],
    ];

    protected ?ReportSource $source = null;

    /**
     * Rows kept per contract table on SCREEN — the Lord DB has thousands of bonds per
     * scheme and Livewire carries every row in the page state. Aggregates always cover
     * every contract; CSV / Excel / PDF exports always carry every row.
     */
    public const SCREEN_ROWS = 500;

    /** Rows per table in the PDF / Print output — dompdf cannot lay out thousands of rows in time. */
    public const PDF_ROWS = 50;

    protected bool $allRows = false;

    protected bool $pdfMode = false;

    protected int $pdfRows = self::PDF_ROWS;

    /** Company-level document: super-admin / HQ staff only (dealers and support never see it). */
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
            ReportSource::LEGACY => __('Lord DB (lordicl · tbl_bond)'),
            ReportSource::NEXT => __('New system (lordicl-next)'),
        ];
    }

    /** The board reads the Lord DB; tests and machines without it fall back to the new system. */
    public static function defaultSource(): string
    {
        return ! app()->runningUnitTests() && LegacyLordSource::available() ? ReportSource::LEGACY : ReportSource::NEXT;
    }

    protected function makeSource(string $key): ReportSource
    {
        return $key === ReportSource::LEGACY ? new LegacyLordSource : new NextSystemSource;
    }

    /** Default window = from the very first bond on record to today, so the first run shows everything. */
    public function mount(): void
    {
        $source = static::defaultSource();
        try {
            $first = $this->makeSource($source)->firstDate();
        } catch (\Throwable) {
            $first = null;
        }

        $this->form->fill([
            'mode' => self::MODE_FULL,
            'source' => $source,
            'pdf_rows' => self::PDF_ROWS,
            'from' => ($first ?? now()->startOfYear())->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(6)->schema([
                Forms\Components\Select::make('mode')->label(__('Report type'))
                    ->options([self::MODE_FULL => __('Full Report'), self::MODE_LITE => __('Simplified report'), self::MODE_DEALERS => __('Dealers Report')])
                    ->default(self::MODE_FULL)->required()->native(false)->selectablePlaceholder(false)->live(),
                Forms\Components\Select::make('source')->label(__('Data source'))
                    ->options(static::sourceOptions())->required()->native(false)->selectablePlaceholder(false),
                Forms\Components\DatePicker::make('from')->label(__('From date'))->required()->native(false)
                    ->visible(fn (Forms\Get $get) => $get('mode') !== self::MODE_DEALERS),
                Forms\Components\DatePicker::make('to')->label(__('To date'))->required()->native(false)
                    ->visible(fn (Forms\Get $get) => $get('mode') !== self::MODE_DEALERS),
                Forms\Components\Select::make('pdf_rows')->label(__('Rows per table in PDF / Print'))
                    ->options([50 => '50', 100 => '100', 200 => '200', 500 => '500'])->default(self::PDF_ROWS)
                    ->native(false)->selectablePlaceholder(false)
                    ->helperText(__('Larger values make the PDF slow — about 10 seconds per 100 rows. CSV / Excel always carry every row.')),
                Forms\Components\Placeholder::make('hint')->label(__('How the dates work'))
                    ->content(__('Contracts started between the two dates are listed; every figure on them is read as on the To date.'))
                    ->visible(fn (Forms\Get $get) => $get('mode') !== self::MODE_DEALERS),
            ]),
        ])->statePath('data');
    }

    /**
     * CSV / Excel re-run the report with every row; PDF / Print re-run it with PDF_ROWS
     * per table (screen tables are capped at SCREEN_ROWS). Aggregates always cover every
     * contract whatever the cap.
     */
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

    /** PDF / Print title follows the report type (Full · Simplified · Dealers). */
    protected function sectionsPdf(bool $inline)
    {
        @ini_set('memory_limit', '1024M');
        $sections = $this->exportSections();
        $mode = (string) ($this->form->getState()['mode'] ?? self::MODE_FULL);
        $title = match ($mode) {
            self::MODE_LITE => __('Business Model Report (Simplified)'),
            self::MODE_DEALERS => __('Dealers Report'),
            default => __('Business Model Report'),
        };

        $output = Pdf::setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('pdf.sections', ['title' => $title, 'sections' => $sections, 'generatedAt' => now()->toDayDateTimeString()])
            ->setPaper('a4', 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print ($output),
            $this->exportSlug().'-'.$mode.'-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** Cap a table for the screen / PDF and say so in the block's key-values. */
    protected function capRows(array &$kv, array $rows): array
    {
        $cap = $this->pdfMode ? $this->pdfRows : self::SCREEN_ROWS;
        if (($this->allRows && ! $this->pdfMode) || count($rows) <= $cap) {
            return $rows;
        }
        $kv[$this->pdfMode ? __('Rows in this PDF') : __('Rows on screen')] = __('First :n of :total — CSV / Excel carry every row', ['n' => number_format($cap), 'total' => number_format(count($rows))]);

        return array_slice($rows, 0, $cap);
    }

    public function run(): void
    {
        @ini_set('memory_limit', '1024M');
        $d = $this->form->getState();
        $mode = (string) ($d['mode'] ?? self::MODE_FULL);
        if ($mode === self::MODE_DEALERS) {
            $d['from'] = '2000-01-01';                 // no period: the current state of the book
            $d['to'] = now()->toDateString();
        }
        $from = Carbon::parse($d['from'])->startOfDay();
        $to = Carbon::parse($d['to'])->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];   // dates typed the wrong way round
        }
        $this->sections = [];
        $this->ran = true;
        $this->pdfRows = max(10, (int) ($d['pdf_rows'] ?? self::PDF_ROWS));
        $this->source = $this->makeSource((string) ($d['source'] ?? static::defaultSource()));

        try {
            match ($mode) {
                self::MODE_LITE => $this->buildLite($from, $to),
                self::MODE_DEALERS => $this->buildLite($from, $to, dealersFromLogins: true),
                default => $this->build($from, $to),
            };
        } catch (QueryException|\PDOException $e) {
            $this->sections = [['heading' => __('Data source unavailable'), 'kv' => [
                __('Source') => $this->source->label(),
                __('Problem') => __('The database could not be read. Check the LEGACY_DB_* settings in .env, or switch the data source.'),
                __('Detail') => Str::limit($e->getMessage(), 300),
            ]]];
        }
    }

    protected function build(Carbon $from, Carbon $to): void
    {
        $plans = Plan::all();
        $byHid = $plans->filter(fn ($p) => $p->hid !== null)->keyBy(fn ($p) => (int) $p->hid);
        $savings = $plans->firstWhere('code', self::SAVINGS_PLAN_CODE);

        $this->overviewSection($from, $to);
        $this->ladderSection($from, $to, $byHid);

        $totals = ['contracts' => 0, 'gold_contracts' => 0, 'grams' => 0.0, 'settled' => 0, 'awaiting' => 0];

        foreach (self::DEALERSHIP_HIDS as $i => $hid) {
            if ($plan = $byHid->get($hid)) {
                $this->schemeSections('1.'.($i + 1), $plan, $from, $to, $totals);
            }
        }
        foreach (self::RETAIL_HIDS as $i => $hid) {
            if ($plan = $byHid->get($hid)) {
                $this->schemeSections('2.'.($i + 1), $plan, $from, $to, $totals);
            }
        }
        if ($savings) {
            $this->savingsSections('3.1', $savings, $from, $to, $totals);
        }

        $this->conclusionSection($from, $to, $totals);
    }

    // ------------------------------------------------------------------ simplified report

    /**
     * Board template — no narrative. Simplified report: dealer schemes list contracts
     * joined in the period, G12 / G11 closed contracts. Dealers Report: dealer schemes
     * list the dealer LOGINS as they stand today, G12 / G11 every closed contract.
     */
    protected function buildLite(Carbon $from, Carbon $to, bool $dealersFromLogins = false): void
    {
        $this->sections[] = $dealersFromLogins
            ? ['heading' => 'Lord Jeweller - Dealers Report', 'kv' => [
                __('As on') => now()->format('d-m-Y'),
            ]]
            : ['heading' => 'Lord Jeweller - Business Model Report', 'kv' => [
                __('Overview Period') => $from->format('d-m-Y').' - '.$to->format('d-m-Y'),
                __('Contracts') => __('Dealers: joined in the period · G12 & G11: closed contracts only'),
            ]];

        foreach (self::LITE as $group) {
            foreach ($group['items'] as $item) {
                $plan = Plan::where('code', $item['code'])->first();
                $joined = ! empty($item['joined']);
                $validity = (int) ($plan?->validity_months ?: 24);

                if ($joined && $dealersFromLogins) {
                    // Dealer LOGINS: Start On = joining (earliest dealership bond, else the login's
                    // creation), End On = joining + validity, Started Amount = full value of every
                    // dealership bond, Delivery Gold = the gold actually allocated on them (share ×
                    // amount only when no allocation is recorded).
                    // Board 2026-09-17: a login counts at a level only when its Started Amount reaches
                    // that scheme's entry minimum (plan master) — a ₹15,000 "Retailer" is not a retailer.
                    $minimum = (float) ($plan?->min_value ?? 0);
                    $contracts = $this->source->dealerLogins($item['level'])
                        ->filter(fn ($u) => $minimum <= 0 || $u->amount >= $minimum)->values()
                        ->map(fn ($u) => (object) [
                            'member_name' => $u->name, 'member_uid' => $u->uid, 'branch' => $u->branch, 'incharge' => $u->incharge, 'gst' => $u->gst,
                            'start_date' => $u->start_date, 'end_date' => $u->start_date->copy()->addMonthsNoOverflow($validity),
                            'amount' => $u->amount, 'monthly' => $u->amount, 'bond_id' => null, 'allocation' => $u->allocation,
                        ]);
                } else {
                    $contracts = $plan ? $this->source->contracts($plan, $from, $to) : collect();
                    if (! $joined) {
                        $contracts = $contracts->filter(fn ($c) => ContractStatus::isClosed($c->status, $c->settled_on, $c->end_date, $to))->values();
                    }
                }

                $rd = isset($item['bonus']) && $contracts->isNotEmpty()
                    ? $this->source->rdEntries($contracts->pluck('bond_id')->all(), $to)
                    : collect();

                $rows = [];
                $started = 0.0;
                $delivery = 0.0;
                foreach ($contracts as $c) {
                    if (isset($item['bonus'])) {
                        $paid = (float) $c->monthly + (float) collect($rd->get($c->bond_id, []))->sum('value');
                        $deliver = round($paid + $item['bonus'] * (float) $c->monthly, 2);
                    } else {
                        $deliver = isset($c->allocation) && $c->allocation !== null
                            ? round((float) $c->allocation, 2)
                            : round((float) $c->amount * $item['share'], 2);
                    }
                    $started += (float) $c->amount;
                    $delivery += $deliver;
                    $row = [
                        ($c->member_uid ? $c->member_name.' ('.$c->member_uid.')' : $c->member_name)
                            .(! empty($c->incharge) ? ' · '.__('Incharge').': '.$c->incharge : ''),
                    ];
                    if (! empty($group['gst'])) {
                        $row[] = (string) ($c->gst ?? '') ?: '—';   // dealers: GST in its own column
                    }
                    $rows[] = [...$row,
                        $this->dmy($c->start_date),
                        $this->dmy($c->end_date),
                        $this->money($c->amount),
                        $this->money($deliver),
                    ];
                }

                $kv = $item['facts'];
                $kv[$joined ? ($dealersFromLogins ? __('Dealers Count') : __('Distributors joined in period')) : __('Closed contracts')] = (string) count($rows);
                if (! $plan) {
                    $kv[__('Data note')] = __('Scheme :code is not in the plan master.', ['code' => $item['code']]);
                } elseif ($note = $this->source->coverageNote($plan)) {
                    $kv[__('Data note')] = $note;
                }
                $rows = $this->capRows($kv, $rows);

                // PDF: every segment starts on its own page (board 2026-09-17).
                $this->sections[] = ['heading' => $group['title'].' — '.$item['no'].'. '.$item['label'], 'page_break' => true, 'kv' => $kv,
                    'columns' => [__('Distributor Name (UID)'), ...(! empty($group['gst']) ? [__('GST No')] : []), __('Start On'), __('End On'), __('Started Amount (₹)'), __('Delivery Gold (₹)')],
                    'rows' => $rows];
            }
        }
    }

    // ------------------------------------------------------------------ overview

    protected function overviewSection(Carbon $from, Carbon $to): void
    {
        $o = $this->source->overview($from, $to);

        $kv = [
            __('Data source') => $this->source->label(),
            __('Head Office') => (string) $o['hq'],
            __('Regional Dealerships') => (string) $o['regional'],
            __('Zonal Dealerships') => (string) $o['zonal'],
            __('District Dealerships') => (string) $o['district'],
            __('Taluka Dealerships (showroom / L-BOX)') => (string) $o['taluk'],
            __('Retail tier (Wholesaler / Retailer / Sub Dealer / Area)') => (string) $o['retail'],
            __('Schemes on offer') => (string) $o['schemes'],
            __('Distributors on record') => (string) $o['members'],
            __('Contracts (bonds) started in period') => $o['contracts'].' / ₹'.$this->money($o['contracts_amount']),
            __('Active as on To date') => $o['active'].' / ₹'.$this->money($o['active_amount']),
            __('Closed as on To date') => $o['closed'].' / ₹'.$this->money($o['closed_amount']),
            __('Installments (RD) received in period') => $o['rd'].' / ₹'.$this->money($o['rd_amount']),
            __('Gold QR allocations in period') => $o['qrs'].' / '.$this->grams($o['qrs_grams']).' / ₹'.$this->money($o['qrs_amount']),
            __('Contract settlements paid in period') => $o['settlements'].' / ₹'.$this->money($o['settlements_amount']),
        ];
        if (! empty($o['settlements_note'])) {
            $kv[__('Settlement note')] = $o['settlements_note'];
        }
        if (! empty($o['qr_note'])) {
            $kv[__('QR register note')] = $o['qr_note'];
        }

        $this->sections[] = ['heading' => __('Lord structure — overview (:from – :to)', ['from' => $this->dmy($from), 'to' => $this->dmy($to)]), 'kv' => $kv];
    }

    /** The dealership ladder — one row per hid level, top to bottom. */
    protected function ladderSection(Carbon $from, Carbon $to, Collection $byHid): void
    {
        $rows = [];
        foreach (Branch::HID_LEVELS as $hid => $level) {
            $plan = $byHid->get($hid);
            if (! $plan) {
                continue;
            }
            $contracts = $this->source->contracts($plan, $from, $to);

            $rows[] = [
                $hid,
                Branch::levelLabel($level),
                $plan->code.' — '.$this->planName($plan),
                '₹'.$this->money($plan->min_value),
                $plan->max_value !== null ? '₹'.$this->money($plan->max_value) : __('No cap'),
                $plan->max_sales_value !== null ? '₹'.$this->money($plan->max_sales_value) : __('No cap'),
                (string) $this->source->dealersOnScheme($plan, $level),
                (string) $contracts->count(),
                '₹'.$this->money($contracts->sum('amount')),
                $this->levelList(Branch::allowedSourceLevels($level)),
            ];
        }

        $this->sections[] = ['heading' => __('Dealership ladder (HQ → Regional → Zonal → District → Taluka → retail tier)'),
            'columns' => [__('Order'), __('Level'), __('Scheme'), __('Entry :label', ['label' => __(self::L_STARTED)]),
                __('Per-bill limit'), __('Monthly sales limit'), $this->source->dealersLabel(), __('Contracts (period)'),
                __(':label total', ['label' => __(self::L_STARTED)]), __('Buys stock from')],
            'rows' => $rows];
    }

    // ------------------------------------------------------------------ offline distributor schemes

    protected function schemeSections(string $no, Plan $plan, Carbon $from, Carbon $to, array &$totals): void
    {
        $title = $no.' '.$this->planName($plan);
        $level = Branch::levelForHid($plan->hid);

        $this->sections[] = ['heading' => __(':title — scheme (:code)', ['title' => $title, 'code' => $plan->code]),
            'kv' => $this->schemeFacts($plan, $level)];

        $contracts = $this->source->contracts($plan, $from, $to);
        $facts = $this->contractFacts($contracts, $plan, $to);
        [$active, $closed] = collect($facts)->partition(fn ($f) => ! $f['closed']);

        $this->contractBlock($title, __('active contracts (bonds)'), $active, $plan);
        $this->contractBlock($title, __('closed contracts (bonds)'), $closed, $plan);

        $this->rollTotals($totals, $facts);
    }

    /** Scheme facts for an offline distributor plan, sales limits included. */
    protected function schemeFacts(Plan $plan, ?string $level): array
    {
        $suppliesTo = collect(Branch::ALLOWED_SOURCES)
            ->filter(fn ($sources) => in_array($level, $sources, true))->keys()->all();

        $settlementGold = $plan->settlement_qr_pct !== null
            ? __(':pct% of the contract share as a gold QR', ['pct' => rtrim(rtrim((string) $plan->settlement_qr_pct, '0'), '.')])
            : ((int) $plan->settlement_bonus_months > 0
                ? __('Paid total + :n bonus month(s) as a gold QR', ['n' => (int) $plan->settlement_bonus_months])
                : __('Matures for manual settlement (withdraw / renewal)'));

        $kv = [
            __('Scheme code') => $plan->code,
            __('Scheme') => $this->planName($plan),
            __('Category') => $level ? Branch::levelLabel($level) : '—',
            $this->source->dealersLabel() => (string) $this->source->dealersOnScheme($plan, $level),
            __('Entry :label (minimum)', ['label' => __(self::L_STARTED)]) => '₹'.$this->money($plan->min_value),
            __('Per-bill limit') => $plan->max_value !== null ? '₹'.$this->money($plan->max_value) : __('No cap'),
            __('Monthly sales limit') => $plan->max_sales_value !== null
                ? '₹'.$this->money($plan->max_sales_value).' '.__('per distributor per calendar month')
                : __('No cap'),
            __('Stock order limit') => __('Outstanding orders ≤ max(BV, invested); HQ staff exempt'),
            __('Buys stock from') => $this->levelList(Branch::allowedSourceLevels($level)),
            __('Supplies stock to') => $suppliesTo ? $this->levelList($suppliesTo) : __('End of chain (no onward supply)'),
            __('Earns stock-transfer margin') => in_array($level, Branch::SELLER_LEVELS, true) ? __('Yes') : __('No'),
            __(':label share (allocated at billing)', ['label' => __(self::L_SPOT)]) => $this->pct($plan->allocation_cont),
            __('Business Value (BV) share') => $this->pct($plan->allocation_bv),
            __('Contract validity') => __(':n months', ['n' => (int) $plan->validity_months]),
            __('Settlement rule') => (string) ($plan->settlement ?: '—'),
            __('Settlement cycle') => $plan->settlement_cycle_months ? __(':n months', ['n' => (int) $plan->settlement_cycle_months]) : '—',
            __('Settlement benefit') => $settlementGold,
            __('Closes on settlement') => $plan->settlement_close ? __('Yes') : __('No — runs to validity, then expiry settlement'),
            __('Billing margin') => $this->pct($plan->billing_margin),
            __('CBC (contract bonus)') => (float) $plan->cbc_value > 0
                ? $this->pct($plan->cbc_value).' × '.(int) $plan->cbc_count : '—',
            __('Genealogy depth') => (string) (int) $plan->level_depth,
            __('MOU') => $this->mouTitle($plan),
        ];
        if ($note = $this->source->coverageNote($plan)) {
            $kv[__('Data note')] = $note;
        }

        return $kv;
    }

    /** One block = aggregate key-values + one row per contract. */
    protected function contractBlock(string $title, string $kind, Collection $facts, Plan $plan): void
    {
        $members = $facts->pluck('member_id')->filter()->unique()->count();
        $awaiting = $facts->filter(fn ($f) => $f['awaiting'])->count();

        $kv = [
            __('Contracts') => (string) $facts->count(),
            __('Distributors') => (string) $members,
            __(':label total', ['label' => __(self::L_STARTED)]) => '₹'.$this->money($facts->sum('amount')),
            __(':label allocated', ['label' => __(self::L_SPOT)]) => $this->grams($facts->sum('spot_g')).' / ₹'.$this->money($facts->sum('spot_cash')),
            __(':label issued', ['label' => __(self::L_YEAR1)]) => $this->grams($facts->sum('y1_g')).' / ₹'.$this->money($facts->sum('y1_cash')),
            __(':label handed over (due date passed)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($facts->sum('y1_handed')),
            __(':label projected (not yet due)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($facts->sum('y1_projected')),
            __(':label paid', ['label' => __(self::L_YEAR2)]) => '₹'.$this->money($facts->sum('y2_cash')),
            __('Settlement amounts (issued + handed over + paid)') => '₹'.$this->money($facts->sum('y1_cash') + $facts->sum('y1_handed') + $facts->sum('y2_cash')),
            __('Awaiting settlement') => (string) $awaiting,
            __('Term concluded (as on To date)') => (string) $facts->where('concluded', true)->count(),
            __('Settlement due · settled') => (string) $facts->where('settlement_due', true)->where('settlement_recorded', true)->count(),
            __('Settlement due · pending') => (string) $facts->where('settlement_due', true)->where('settlement_recorded', false)->count(),
        ];

        $rows = $facts->map(fn ($f) => [
            $f['contract_no'],
            $f['member'],
            $f['branch'],
            $this->dmy($f['start']),
            $this->dmy($f['end']),
            $f['term'],
            $this->money($f['amount']),
            $this->grams($f['spot_g']),
            $this->money($f['spot_cash']),
            $f['y1_cash'] > 0 ? $this->money($f['y1_cash']) : ($f['y1_handed'] > 0 ? $this->money($f['y1_handed']) : ($f['y1_projected'] > 0 ? $this->money($f['y1_projected']) : '—')),
            $f['y1_note'],
            $f['y2_cash'] > 0 ? $this->money($f['y2_cash']) : '—',
            $f['y2_note'],
            $f['status'],
        ])->values()->all();
        $rows = $this->capRows($kv, $rows);

        $this->sections[] = ['heading' => "{$title} — {$kind}", 'kv' => $kv,
            'columns' => [__('Contract'), __('Distributor'), __('Branch'), __('Started on'), __('Ends on'), __('Term'),
                __(':label (₹)', ['label' => __(self::L_STARTED)]),
                __(':label (g)', ['label' => __(self::L_SPOT)]), __(':label (₹)', ['label' => __(self::L_SPOT)]),
                __(':label (₹)', ['label' => __(self::L_YEAR1)]), __('1-year settlement'),
                __(':label (₹)', ['label' => __(self::L_YEAR2)]), __('2nd-year settlement'),
                __('Status')],
            'rows' => $rows];
    }

    // ------------------------------------------------------------------ customer savings scheme (P209)

    protected function savingsSections(string $no, Plan $plan, Carbon $from, Carbon $to, array &$totals): void
    {
        $title = $no.' '.$this->planName($plan);
        $grams = (float) $plan->rd_qr_grams;

        try {
            $installment = '₹'.$this->money(app(GoldQrPricing::class)->price($plan));
        } catch (\Throwable) {
            $installment = __('Live gold rate not set');
        }

        $kv = [
            __('Scheme code') => $plan->code,
            __('Scheme') => $this->planName($plan),
            __('Category') => __('Customer-level jewellery savings (recurring)'),
            __('Installments') => __(':n monthly installments (month 1 = joining, then :m renewals)', ['n' => (int) $plan->validity_months, 'm' => max(0, (int) $plan->validity_months - 1)]),
            __('First installment (:label minimum)', ['label' => __(self::L_STARTED)]) => '₹'.$this->money($plan->min_value),
            __('Installment amount today') => $installment.' '.__('(fixed at the live :g gold price incl. charges & GST)', ['g' => $this->mg($grams)]),
            __(':label per installment', ['label' => __(self::L_SPOT)]) => __(':g gold QR on every installment', ['g' => $this->mg($grams)]),
            __('Settlement rule') => (string) ($plan->settlement ?: '—'),
            __('Settlement cycle') => __(':n months', ['n' => (int) $plan->settlement_cycle_months]),
            __(':label at settlement', ['label' => __(self::L_YEAR1)]) => __('Paid total + :n bonus month(s) as a gold QR', ['n' => (int) $plan->settlement_bonus_months]),
            __('Closes on settlement') => $plan->settlement_close ? __('Yes') : __('No'),
            __('Billing margin') => $this->pct($plan->billing_margin),
            __('Renewal margin') => $this->pct($plan->renewal_margin),
            __('MOU') => $this->mouTitle($plan),
        ];
        if ($note = $this->source->coverageNote($plan)) {
            $kv[__('Data note')] = $note;
        }
        $this->sections[] = ['heading' => __(':title — scheme (:code)', ['title' => $title, 'code' => $plan->code]), 'kv' => $kv];

        $contracts = $this->source->contracts($plan, $from, $to);
        $facts = $this->contractFacts($contracts, $plan, $to);
        $all = collect($facts);

        // Installments dated inside the window = joinings in the window + renewals paid in the window.
        $inPeriod = $this->source->rdEntries($contracts->pluck('bond_id')->all(), $to)->flatten(1)
            ->filter(fn ($r) => $r->paid_on->gte($from));

        $this->sections[] = ['heading' => __(':title — installments & :g gold allocations', ['title' => $title, 'g' => $this->mg($grams)]), 'kv' => [
            __('Contracts started in period') => (string) $contracts->count(),
            __('Installments received (as on To date)') => (string) $all->sum('installments'),
            __('Amount collected (as on To date)') => '₹'.$this->money($all->sum('collected')),
            __('Installments dated inside the period') => ($contracts->count() + $inPeriod->count()).' / ₹'.$this->money($all->sum('amount') + $inPeriod->sum('value')),
            __(':g gold allocations', ['g' => $this->mg($grams)]) => (string) $all->sum('spot_count'),
            __(':label allocated', ['label' => __(self::L_SPOT)]) => $this->grams($all->sum('spot_g')).' / ₹'.$this->money($all->sum('spot_cash')),
            __('Allocations redeemed') => (string) $all->sum('spot_redeemed'),
            __('Allocations pending redemption') => (string) ($all->sum('spot_count') - $all->sum('spot_redeemed')),
            __(':label issued', ['label' => __(self::L_YEAR1)]) => $this->grams($all->sum('y1_g')).' / ₹'.$this->money($all->sum('y1_cash')),
            __(':label handed over (due date passed)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($all->sum('y1_handed')),
            __(':label projected (not yet due)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($all->sum('y1_projected')),
            __('Term concluded (as on To date)') => (string) $all->where('concluded', true)->count(),
            __('Fully paid (:n of :n dues)', ['n' => (int) $plan->validity_months]) => (string) $all->filter(fn ($f) => $f['installments'] >= $f['dues_required'])->count(),
            __(':g pieces handed over without a QR record', ['g' => $this->mg($grams)]) => (string) $all->sum('qr_handed'),
            __('Installments without a :g QR', ['g' => $this->mg($grams)]) => (string) $all->sum('qr_missing'),
            __('Settlement due · settled (issued / handed over)') => $all->where('settlement_due', true)->where('settlement_recorded', true)->count().' / ₹'.$this->money($all->where('settlement_due', true)->sum('y1_cash') + $all->where('settlement_due', true)->sum('y1_handed')),
            __('Settlement due · pending') => $all->where('settlement_due', true)->where('settlement_recorded', false)->count().' / ₹'.$this->money($all->where('settlement_due', true)->where('settlement_recorded', false)->sum('y1_projected')).' '.__('expected'),
        ]];

        [$active, $closed] = $all->partition(fn ($f) => ! $f['closed']);
        $this->savingsBlock($title, __('active contracts (bonds)'), $active, $grams);
        $this->savingsBlock($title, __('closed contracts (bonds)'), $closed, $grams);

        $this->rollTotals($totals, $facts);
    }

    protected function savingsBlock(string $title, string $kind, Collection $facts, float $grams): void
    {
        $kv = [
            __('Contracts') => (string) $facts->count(),
            __('Distributors') => (string) $facts->pluck('member_id')->filter()->unique()->count(),
            __(':label total (first installments)', ['label' => __(self::L_STARTED)]) => '₹'.$this->money($facts->sum('amount')),
            __('Installments received') => (string) $facts->sum('installments'),
            __('Amount collected') => '₹'.$this->money($facts->sum('collected')),
            __(':g gold allocations', ['g' => $this->mg($grams)]) => (string) $facts->sum('spot_count'),
            __(':label allocated', ['label' => __(self::L_SPOT)]) => $this->grams($facts->sum('spot_g')).' / ₹'.$this->money($facts->sum('spot_cash')),
            __(':label issued', ['label' => __(self::L_YEAR1)]) => $this->grams($facts->sum('y1_g')).' / ₹'.$this->money($facts->sum('y1_cash')),
            __(':label handed over (due date passed)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($facts->sum('y1_handed')),
            __(':label projected (not yet due)', ['label' => __(self::L_YEAR1)]) => '₹'.$this->money($facts->sum('y1_projected')),
            __('Term concluded (as on To date)') => (string) $facts->where('concluded', true)->count(),
            __(':g pieces handed over without a QR record', ['g' => $this->mg($grams)]) => (string) $facts->sum('qr_handed'),
            __('Installments without a :g QR', ['g' => $this->mg($grams)]) => (string) $facts->sum('qr_missing'),
            __('Settlement due · settled (issued / handed over)') => (string) $facts->where('settlement_due', true)->where('settlement_recorded', true)->count(),
            __('Settlement due · pending') => (string) $facts->where('settlement_due', true)->where('settlement_recorded', false)->count(),
        ];

        $rows = $facts->map(fn ($f) => [
            $f['contract_no'],
            $f['member'],
            $f['branch'],
            $this->dmy($f['start']),
            $f['term'],
            $this->money($f['amount']),
            (string) $f['installments'],
            $f['dues_note'],
            $this->money($f['collected']),
            (string) $f['spot_count'],
            $this->grams($f['spot_g']),
            $this->money($f['spot_cash']),
            (string) $f['spot_redeemed'],
            $f['qr_note'],
            $f['y1_cash'] > 0 ? $this->money($f['y1_cash']) : ($f['y1_handed'] > 0 ? $this->money($f['y1_handed']) : ($f['y1_projected'] > 0 ? $this->money($f['y1_projected']) : '—')),
            $f['y1_note'],
            $f['status'],
        ])->values()->all();
        $rows = $this->capRows($kv, $rows);

        $this->sections[] = ['heading' => "{$title} — {$kind}", 'kv' => $kv,
            'columns' => [__('Contract'), __('Distributor'), __('Branch'), __('Started on'), __('Term'),
                __(':label (₹)', ['label' => __(self::L_STARTED)]), __('Installments'), __('Dues paid'), __('Collected (₹)'),
                __(':g allocations', ['g' => $this->mg($grams)]),
                __(':label (g)', ['label' => __(self::L_SPOT)]), __(':label (₹)', ['label' => __(self::L_SPOT)]), __('Redeemed'),
                __(':g QR payments', ['g' => $this->mg($grams)]),
                __(':label (₹)', ['label' => __(self::L_YEAR1)]), __('1-year settlement'), __('Status')],
            'rows' => $rows];
    }

    // ------------------------------------------------------------------ conclusion

    protected function conclusionSection(Carbon $from, Carbon $to, array $t): void
    {
        $o = $this->source->overview($from, $to);
        $ladder = $o['regional'] + $o['zonal'] + $o['district'] + $o['taluk'];

        $this->sections[] = ['heading' => __('Conclusion — a traditional, contract-backed business model'), 'kv' => [
            __('Written contract per scheme') => __('Every scheme sale creates a bond and a numbered contract with fixed start and end dates; :n contracts in the schemes above for this period.', ['n' => $t['contracts']]),
            __('Physical gold behind the schemes') => __(':n of :total contracts carry gold QR allocations totalling :g, redeemable only against branch stock.', ['n' => $t['gold_contracts'], 'total' => $t['contracts'], 'g' => $this->grams($t['grams'])]),
            __('Physical distribution chain') => __(':ladder dealers on the HQ → Regional → Zonal → District → Taluka ladder and :retail retail-tier dealers; stock moves only along the approved source matrix.', ['ladder' => $ladder, 'retail' => $o['retail']]),
            __('Rule-based settlement') => __('Each scheme settles by its own written rule (12- or 24-month cycle); :settled settled, :awaiting awaiting their due date.', ['settled' => $t['settled'], 'awaiting' => $t['awaiting']]),
            __('Sales discipline') => __('Per-bill and monthly caps per scheme, stock orders limited to max(BV, invested), and stock sourced only from the approved supplier levels.'),
            __('Report basis') => __(':source — live figures, contracts started :from – :to, valued as on :to.', ['source' => $this->source->label(), 'from' => $this->dmy($from), 'to' => $this->dmy($to)]),
        ]];
    }

    // ------------------------------------------------------------------ contract facts

    /**
     * Everything a row / aggregate needs, per contract, as on $asOf. QRs minted on or
     * after the settlement date are the settlement QR (1-year Gold Stock); the rest are
     * the billing / installment allocations (Spot Gold Stock). Cash settlement generated
     * by HQ on expiry is the 2nd-year Gold Stock.
     */
    protected function contractFacts(Collection $contracts, Plan $plan, Carbon $asOf): array
    {
        $bondIds = $contracts->pluck('bond_id')->filter()->all();
        $qrs = $this->source->qrs($bondIds, $asOf);
        $rd = $this->source->rdEntries($bondIds, $asOf);
        $paid = $this->source->settlements($contracts->pluck('id')->all(), $asOf);

        $cycle = (int) $plan->settlement_cycle_months;
        $goldRate = (float) (LiveRate::latestFor('IN')?->gold ?? 0);
        $closesAtCycle = $cycle > 0 && $cycle <= 12 && (bool) $plan->settlement_close;   // RD-style: no 2nd-year leg
        $offline = $this->source->settlesOffline();

        return $contracts->map(function ($c) use ($qrs, $rd, $paid, $plan, $asOf, $cycle, $goldRate, $closesAtCycle, $offline) {
            $settledOn = $c->settled_on;
            $settledAsOf = $settledOn !== null && $settledOn->lte($asOf);
            $closed = ContractStatus::isClosed($c->status, $settledOn, $c->end_date, $asOf);

            $concluded = $c->end_date !== null && $c->end_date->lte($asOf);   // the scheme term has run its course
            $y1Due = $cycle > 0 && $c->start_date ? $c->start_date->copy()->addMonthsNoOverflow($cycle) : null;
            $monthly = (float) $c->monthly;

            // Settlement QR = minted on/after the settlement date. Without a settlement date
            // on record (Lord DB), a QR minted on/after the 12-month due date and worth more
            // than one installment is taken as the settlement QR.
            $bondQrs = collect($qrs->get($c->bond_id, []));
            [$settleQrs, $spotQrs] = $bondQrs->partition(function ($q) use ($settledAsOf, $settledOn, $y1Due, $c, $monthly) {
                if ($settledAsOf) {
                    return $q->created_at->gte($settledOn->copy()->startOfDay());
                }

                return $y1Due !== null && $q->created_at->gte($y1Due->copy()->startOfDay())
                    && (! $c->is_rd || $q->cash_worth > 1.5 * $monthly);
            });

            $entries = collect($rd->get($c->bond_id, []));
            $collected = $c->is_rd ? $monthly + (float) $entries->sum('value') : (float) $c->amount;
            $installments = $c->is_rd ? 1 + $entries->count() : 1;
            $duesRequired = $c->is_rd ? max(1, (int) $plan->validity_months) : 1;

            // 1-year Gold Stock: issued settlement QR, else the plan formula projected on
            // what has been collected so far (12-month-cycle plans only).
            $y1Cash = (float) $settleQrs->sum('cash_worth');
            $y1G = (float) $settleQrs->sum('gram_worth');
            $y1Projected = 0.0;
            $issuedOn = $settledOn ?? $settleQrs->min('created_at');
            if ($y1Cash <= 0 && ! $settledAsOf && $cycle > 0 && $cycle <= 12) {
                if ((int) $plan->settlement_bonus_months > 0) {
                    $y1Projected = round($collected + (int) $plan->settlement_bonus_months * $monthly, 2);
                } elseif ($plan->settlement_qr_pct !== null) {
                    $base = (float) $c->amount;
                    if ((float) $plan->allocation_cont > 0) {
                        $base = round($base * (float) $plan->allocation_cont / 100, 2);
                    }
                    $y1Projected = round($base * (float) $plan->settlement_qr_pct / 100, 2);
                }
            }
            // Lord DB: no register, so a settlement whose due date has passed was handed over by hand.
            $y1Handed = 0.0;
            if ($offline && $y1Cash <= 0 && $y1Projected > 0 && $y1Due && $y1Due->lte($asOf)) {
                $y1Handed = $y1Projected;
                $y1Projected = 0.0;
            }
            $y1Note = match (true) {
                $y1Cash > 0 => __('Issued :d · ₹:a', ['d' => $this->dmy($issuedOn), 'a' => $this->money($y1Cash)]),
                $y1Handed > 0 => __('Handed over · due :d · ₹:a', ['d' => $this->dmy($y1Due), 'a' => $this->money($y1Handed)]),
                $y1Projected > 0 && $y1Due && $y1Due->lte($asOf) => __('Due :d · NOT recorded · ₹:a expected', ['d' => $this->dmy($y1Due), 'a' => $this->money($y1Projected)]),
                $y1Projected > 0 && $closed => __('Closed · projected on collections'),
                $y1Projected > 0 => __('Projected · due :d', ['d' => $this->dmy($y1Due)]),
                $cycle > 12 => __('No 12-month settlement (:n-month cycle)', ['n' => $cycle]),
                $settledAsOf => __('Settled :d', ['d' => $this->dmy($settledOn)]),
                default => '—',
            };

            // 2nd-year Gold Stock: cash settlement generated by HQ on expiry.
            $cash = collect($paid->get($c->id, []));
            $y2Cash = (float) $cash->sum('amount');
            $y2Due = $cycle > 12 && $y1Due ? $y1Due : $c->end_date;
            $y2Handed = $offline && $y2Cash <= 0 && ! $closesAtCycle && $y2Due !== null && $y2Due->lte($asOf);
            $y2Note = match (true) {
                $y2Cash > 0 => __('Paid :d', ['d' => $this->dmy($cash->max('paid_on'))]),
                $closesAtCycle => '—',
                $y2Handed => __('Handed over on expiry :d · value per MOU', ['d' => $this->dmy($y2Due)]),
                $c->status === 'matured' && $settledAsOf => __('Matured :d · awaiting settlement', ['d' => $this->dmy($settledOn)]),
                $closed && $y2Due && $y2Due->lt($asOf) => __('Expired :d · awaiting settlement', ['d' => $this->dmy($y2Due)]),
                $y2Due !== null => __('Due :d', ['d' => $this->dmy($y2Due)]),
                default => '—',
            };

            $awaiting = $y2Cash <= 0 && ! $closesAtCycle && ! $y2Handed && (
                ($c->status === 'matured' && $settledAsOf)
                || ($closed && $y2Due && $y2Due->lt($asOf))
            );

            $status = $closed
                ? ($c->status === 'matured' ? __('Matured') : __('Closed'))
                : __('Active');

            $settlementDue = $closesAtCycle ? ($y1Due !== null && $y1Due->lte($asOf)) : $concluded;

            // Spot Gold Stock. RD plans in the Lord DB: every installment paid = one 100 mg
            // piece handed over, whether or not the QR register (from Dec 2025) holds it;
            // pieces without a QR are valued at what was paid for them.
            $pieceGrams = (float) $plan->rd_qr_grams;
            $qrRegistered = $spotQrs->count();
            $spotCount = $qrRegistered;
            $spotG = (float) $spotQrs->sum('gram_worth');
            $spotCash = (float) $spotQrs->sum('cash_worth');
            $qrHanded = 0;
            if ($offline && $c->is_rd && $pieceGrams > 0 && $installments > $qrRegistered) {
                $qrHanded = $installments - $qrRegistered;
                $spotCount = $installments;
                $spotG = round($installments * $pieceGrams, 4);
                $spotCash = max($spotCash, $collected);
            }
            $qrMissing = $c->is_rd ? max(0, $installments - $spotCount) : 0;

            return [
                'contract_no' => $c->contract_no,
                'member_id' => $c->member_id,
                'member' => $c->member,
                'branch' => $c->branch,
                'start' => $c->start_date,
                'end' => $c->end_date,
                'amount' => (float) $c->amount,
                'installments' => $installments,
                'dues_required' => $duesRequired,
                'dues_note' => $c->is_rd ? __(':p of :n paid', ['p' => $installments, 'n' => $duesRequired]) : '—',
                'concluded' => $concluded,
                'term' => $concluded
                    ? __('Concluded :d', ['d' => $this->dmy($c->end_date)])
                    : ($c->end_date ? __('Runs to :d', ['d' => $this->dmy($c->end_date)]) : '—'),
                'settlement_due' => $settlementDue,
                'settlement_recorded' => $y1Cash > 0 || $y2Cash > 0 || $y1Handed > 0 || $y2Handed,
                'qr_missing' => $qrMissing,
                'qr_handed' => $qrHanded,
                'qr_note' => match (true) {
                    ! $c->is_rd => '—',
                    $qrHanded > 0 => __(':n of :n paid · ₹:a (:q QR-registered, :h handed over)', ['n' => $installments, 'a' => $this->money($spotCash), 'q' => $qrRegistered, 'h' => $qrHanded]),
                    $qrMissing === 0 => __(':n of :n issued · ₹:a', ['n' => $installments, 'a' => $this->money($spotCash)]),
                    default => __(':i of :n issued · :m missing · ₹:a', ['i' => $qrRegistered, 'n' => $installments, 'm' => $qrMissing, 'a' => $this->money($spotCash)]),
                },
                'collected' => $collected,
                'spot_count' => $spotCount,
                'spot_g' => $spotG,
                'spot_cash' => $spotCash,
                'spot_redeemed' => $spotQrs->where('status', 'redeemed')->count(),
                'y1_cash' => $y1Cash,
                'y1_g' => $y1G > 0 ? $y1G : ($y1Cash > 0 && $goldRate > 0 ? round($y1Cash / $goldRate, 4) : 0.0),
                'y1_projected' => $y1Projected,
                'y1_handed' => $y1Handed,
                'y1_note' => $y1Note,
                'y2_cash' => $y2Cash,
                'y2_note' => $y2Note,
                'closed' => $closed,
                'awaiting' => $awaiting,
                'settled' => $y1Cash > 0 || $y2Cash > 0 || $y1Handed > 0 || $y2Handed,
                'status' => $status,
            ];
        })->all();
    }

    protected function rollTotals(array &$t, array $facts): void
    {
        foreach ($facts as $f) {
            $t['contracts']++;
            if ($f['spot_g'] > 0 || $f['y1_g'] > 0) {
                $t['gold_contracts']++;
            }
            $t['grams'] += $f['spot_g'] + $f['y1_g'];
            $t['settled'] += $f['settled'] ? 1 : 0;
            $t['awaiting'] += $f['awaiting'] ? 1 : 0;
        }
    }

    // ------------------------------------------------------------------ formatting

    protected function planName(Plan $plan): string
    {
        $name = $plan->name;

        return is_array($name) ? (string) ($name['en'] ?? reset($name) ?: $plan->code) : (string) ($name ?: $plan->code);
    }

    protected function mouTitle(Plan $plan): string
    {
        $t = $plan->mou?->title;

        return is_array($t) ? (string) ($t['en'] ?? $t[app()->getLocale()] ?? reset($t) ?: '—') : (string) ($t ?: '—');
    }

    protected function levelList(array $levels): string
    {
        return collect($levels)->map(fn ($l) => Branch::levelLabel($l))->implode(', ') ?: '—';
    }

    protected function grams($g): string
    {
        return number_format((float) $g, 3).' g';
    }

    protected function mg(float $grams): string
    {
        return $grams >= 1 ? rtrim(rtrim(number_format($grams, 3), '0'), '.').' g' : (int) round($grams * 1000).' mg';
    }

    protected function pct($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3), '0'), '.').'%';
    }
}
