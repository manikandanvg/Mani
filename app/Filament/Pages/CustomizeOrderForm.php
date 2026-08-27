<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\BranchOrderRequest;
use App\Models\LiveRate;
use App\Models\Member;
use App\Services\BranchOrderService;
use App\Services\SalesReturnService;
use App\Support\CustomizeOrderPricing;
use App\Support\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Trade — Customize Order Form (board 2026-08-26, corrected the same day). A distributor
 * describes bespoke gold/silver pieces (chain, necklace, …) for a customer and sends the
 * order to its supplier. Every line is priced live: (live rate + HQ margin) × grams, then
 * making + wastage % from the Charge Bracket slab for that weight, GST on top — typing the
 * grams is all it takes. The customer is an existing member (whose RD 100 mg coins can be
 * applied; a Sales Return is raised) or a new customer captured on the order only. Payment
 * is split between the branch's cash stock and the branch wallet and must cover the total
 * in full before the order proceeds.
 *
 * Money and stock logic live in BranchOrderService::submitCustomize; this page only
 * collects input and previews the maths.
 */
class CustomizeOrderForm extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use \App\Filament\Concerns\HiddenFromSupport;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'Customize Order Form';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Customize Order Form — bespoke gold & silver from your supplier';

    protected static string $view = 'filament.pages.customize-order-form';

    public ?array $data = [];

    public function getSubheading(): ?string
    {
        if (OrderForm::windowExempt()) {
            return 'HQ / super admin — ordering open round the clock.';
        }

        return OrderForm::orderingOpen()
            ? 'Ordering window 9:00 AM – 9:00 PM · OPEN now.'
            : 'Ordering window 9:00 AM – 9:00 PM · CLOSED now — orders cannot be submitted.';
    }

    public function mount(): void
    {
        $this->form->fill($this->blankState());
    }

    protected function blankState(): array
    {
        return [
            'branch_id' => auth()->user()?->branch_id ?? Branch::min('id'),
            'customer_mode' => 'existing',
            'member_id' => null,
            'customer' => [],
            'lines' => [],
            'coin_product_id' => CustomizeOrderPricing::coinProduct()?->id,
            'coin_qty' => null,
            'coin_collect_on' => null,
            'pay_cash' => null,
            'pay_wallet' => null,
            'attachments' => [],
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('rates')->hiddenLabel()->columnSpanFull()
                    ->content(fn () => $this->rateBanner()),

                Section::make('Supplier')->schema([
                    Placeholder::make('supplier')->hiddenLabel()->content(fn () => $this->supplierBanner()),
                ]),

                // ---------- Customer: existing member (coins possible) or a new walk-in ----------
                Section::make('Customer')
                    ->icon('heroicon-o-user-circle')
                    ->description('A new customer is kept with this order only — nothing is written to Distributors. Coins can be applied for existing customers.')
                    ->columns(3)
                    ->schema([
                        ToggleButtons::make('customer_mode')->hiddenLabel()->inline()
                            ->options(['existing' => 'Existing customer', 'new' => 'New customer'])
                            ->default('existing')->live()->columnSpanFull(),
                        Select::make('member_id')->label('Customer (distributor)')
                            ->visible(fn (Get $get) => $get('customer_mode') !== 'new')
                            ->required(fn (Get $get) => $get('customer_mode') !== 'new')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Member::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('member_code', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%"))
                                ->limit(50)->get()
                                ->mapWithKeys(fn ($m) => [$m->id => "{$m->name} ({$m->member_code}) · {$m->phone}"])->all())
                            ->getOptionLabelUsing(fn ($v) => ($m = Member::find($v)) ? "{$m->name} ({$m->member_code}) · {$m->phone}" : null)
                            ->columnSpan(3),
                        TextInput::make('customer.name')->label('Name')
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')
                            ->required(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(200),
                        TextInput::make('customer.phone')->label('Phone')->tel()
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')
                            ->required(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(20),
                        TextInput::make('customer.email')->label('Email')->email()
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(150),
                        TextInput::make('customer.address')->label('Address')
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(200),
                        TextInput::make('customer.city')->label('City')
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(120),
                        TextInput::make('customer.pincode')->label('Pincode')
                            ->visible(fn (Get $get) => $get('customer_mode') === 'new')->maxLength(12),
                    ]),

                // ---------- Items ----------
                Section::make('Items')
                    ->icon('heroicon-o-sparkles')
                    ->description('Type the grams — the live rate, HQ margin, the Charge Bracket making/wastage % for that weight and GST are applied automatically.')
                    ->schema([
                        Placeholder::make('order_limit_banner')->hiddenLabel()
                            ->visible(fn () => (bool) auth()->user()?->isDistributor() && (float) auth()->user()->orderLimit() > 0)
                            ->content(function (Get $get) {
                                $limit = (float) auth()->user()->orderLimit();
                                $pending = (float) BranchOrderRequest::where('branch_id', $get('branch_id'))
                                    ->where('status', 'pending')->sum('grand_total');

                                return new HtmlString(sprintf(
                                    '<span class="text-sm">%s <b>₹%s</b> · %s <b>₹%s</b> · %s <b class="%s">₹%s</b></span>',
                                    e(__('Order limit')), Money::group($limit),
                                    e(__('pending approval')), Money::group($pending),
                                    e(__('available now')), $limit - $pending > 0 ? 'text-green-600' : 'text-red-600',
                                    Money::group(max(0, $limit - $pending))
                                ));
                            }),
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel('Add Items')
                            ->reorderable(false)
                            ->columns(12)
                            ->live()
                            ->schema([
                                Select::make('material')->label('Item')
                                    ->options(CustomizeOrderPricing::MATERIALS)
                                    ->default('gold')->required()->live()->native(false)
                                    ->columnSpan(2),
                                TextInput::make('description')->label('Description')
                                    ->placeholder('e.g. gold chain, necklace, bangle set …')
                                    ->required()->maxLength(200)
                                    ->columnSpan(5),
                                TextInput::make('grams')->label('Quantity (g)')
                                    ->numeric()->minValue(0.001)->maxValue(BranchOrderService::MAX_LINE_QTY)
                                    ->required()->live(onBlur: true)->suffix('g')
                                    ->columnSpan(2),
                                Placeholder::make('net_total')->label('Net total')
                                    ->content(fn (Get $get) => new HtmlString('<span class="font-semibold text-base">₹'
                                        . Money::group($this->priceOf($get)['line_total']) . '</span>'))
                                    ->columnSpan(3),
                                Placeholder::make('price')->label('Price')
                                    ->content(fn (Get $get) => $this->priceBreakdown($get))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                // ---------- Coins (existing customers only) ----------
                Section::make('Customer coins (optional)')
                    ->icon('heroicon-o-circle-stack')
                    ->description('Existing RD customers may apply the value of their accumulated 100 mg coins. Recording them raises a Sales Return: collect the coins at the agreed date & time and mark them collected under Trade → Sales Returns before the supplier approves the order.')
                    ->visible(fn (Get $get) => $get('customer_mode') !== 'new')
                    ->columns(4)
                    ->schema([
                        Select::make('coin_product_id')->label('Coin')
                            ->options(fn () => CustomizeOrderPricing::coinProductOptions())
                            ->searchable()->live()->native(false)
                            ->columnSpan(2),
                        TextInput::make('coin_qty')->label('No. of coins')
                            ->numeric()->integer()->minValue(0)->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('coin_qty', max(0, (int) $state) ?: null)),
                        Placeholder::make('coin_value')->label('Coin credit')
                            ->content(fn (Get $get) => $this->coinCredit($get)),
                        DateTimePicker::make('coin_collect_on')->label('Collect coins on (date & time)')
                            ->seconds(false)->native(false)
                            ->required(fn (Get $get) => (float) $get('coin_qty') > 0)
                            ->columnSpan(2),
                    ]),

                // ---------- Payment: split between cash stock and branch wallet ----------
                Section::make('Payment & summary')
                    ->icon('heroicon-o-banknotes')
                    ->description('Pay from your cash stock balance and/or your branch wallet balance. The order proceeds to the supplier only when the total is covered in full.')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('summary')->hiddenLabel()
                            ->content(fn (Get $get) => $this->summary($get))
                            ->columnSpanFull(),
                        TextInput::make('pay_cash')->label('From cash stock (₹)')
                            ->numeric()->minValue(0)->live(onBlur: true)->prefix('₹')
                            ->maxValue(fn (Get $get) => BranchOrderService::cashStockBalance((int) $get('branch_id')))
                            ->validationMessages(['max' => 'More than your cash stock balance.'])
                            ->helperText(fn (Get $get) => 'Cash stock balance: ₹'
                                . Money::group(BranchOrderService::cashStockBalance((int) $get('branch_id')))),
                        TextInput::make('pay_wallet')->label('From branch wallet (₹)')
                            ->numeric()->minValue(0)->live(onBlur: true)->prefix('₹')
                            ->maxValue(fn (Get $get) => (float) (Branch::find((int) $get('branch_id'))?->digi_cash_balance ?? 0))
                            ->validationMessages(['max' => 'More than your branch wallet balance.'])
                            ->helperText(fn (Get $get) => 'Branch wallet balance: ₹'
                                . Money::group((float) (Branch::find((int) $get('branch_id'))?->digi_cash_balance ?? 0))),
                        Placeholder::make('payment_status')->hiddenLabel()
                            ->content(fn (Get $get) => $this->paymentStatus($get))
                            ->columnSpanFull(),
                        Textarea::make('payment_remarks')->label('Remarks')->rows(2)->columnSpanFull(),
                        FileUpload::make('attachments')
                            ->label('Payment receipt (photo / PDF)')
                            ->helperText('Optional — up to 5 files, 8 MB each. Stored privately; only your supplier and Head Office can open them.')
                            ->multiple()->maxFiles(5)->maxSize(8192)->appendFiles()
                            ->disk('local')->directory('order-receipts')->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->previewable()
                            ->panelLayout('grid')->imagePreviewHeight('120')
                            ->storeFileNamesIn('attachment_names')
                            ->columnSpanFull(),
                        Hidden::make('branch_id'),
                    ]),
            ])
            ->statePath('data');
    }

    // ── Preview helpers ───────────────────────────────────────────────

    protected function rateBanner(): HtmlString
    {
        $rate = LiveRate::latestFor('IN');
        if (! $rate) {
            return new HtmlString('<span style="color:#ab222f">Live rate not set — customised orders cannot be priced.</span>');
        }
        $chip = fn (string $label, string $material, string $color) => '<span style="margin-right:1.5rem;font-weight:600">'
            . '<span style="color:' . $color . '">● ' . $label . '</span> ₹' . Money::group(CustomizeOrderPricing::liveRate($material, $rate)) . '/g'
            . ' <span style="color:#e5e7eb;font-weight:400">+ margin ₹' . Money::group(CustomizeOrderPricing::marginPerGram($material)) . '/g</span></span>';

        return new HtmlString('<div class="text-sm" style="padding:.5rem .9rem;border-radius:.6rem;background:linear-gradient(90deg,#8a1b26,#ab222f);color:#fff">'
            . $chip('GOLD', 'gold', '#e6ad46') . $chip('SILVER', 'silver', '#cbd5e1')
            . '<span style="color:#f3f4f6">Charges per Charge Bracket · GST ' . rtrim(rtrim(number_format(CustomizeOrderPricing::gstPct(), 2), '0'), '.') . '%</span>'
            . '</div>');
    }

    protected function supplierBanner(): HtmlString
    {
        $branch = auth()->user()?->branch;
        $name = $branch?->sourceBranch?->name ?? 'Head Office';

        $html = '<div class="text-sm">This customised order goes to <span class="font-semibold">' . e($name) . '</span> — it appears under their Order Requests marked <b>Customize order</b>.';
        if (auth()->user()?->isDistributor()) {
            $html .= ' <span class="text-gray-500">To change your supplier, raise a request under Settings → Order Source Request.</span>';
        }

        return new HtmlString($html . '</div>');
    }

    protected function priceOf(Get $get): array
    {
        return CustomizeOrderPricing::priceLine((string) ($get('material') ?: 'gold'), (float) $get('grams'));
    }

    protected function priceBreakdown(Get $get): HtmlString
    {
        $p = $this->priceOf($get);
        $pct = fn (float $v) => rtrim(rtrim(number_format($v, 3), '0'), '.');
        $slab = $p['bracket_id'] ? '' : ' <span style="color:#b45309">(no Charge Bracket covers this weight — no charges applied)</span>';

        return new HtmlString(sprintf(
            '<span class="text-xs text-gray-600">Live ₹%s + margin ₹%s = <b>₹%s /g</b> × %s g = ₹%s · charges making %s%% + wastage %s%% = ₹%s%s · GST %s%% = ₹%s → <b>Net ₹%s</b></span>',
            Money::group($p['rate']), Money::group($p['margin_per_g']), Money::group($p['unit_price']),
            $pct((float) $get('grams')), Money::group($p['base']),
            $pct($p['making_pct']), $pct($p['wastage_pct']), Money::group($p['charges']), $slab,
            $pct($p['gst_pct']), Money::group($p['gst']), Money::group($p['line_total'])
        ));
    }

    /** @return array{count:int,cross:float,gst:float,grand:float,coin:float,due:float,paid:float} */
    protected function totals(Get $get): array
    {
        $count = 0;
        $cross = 0.0;
        $gst = 0.0;
        foreach ((array) ($get('lines') ?? []) as $l) {
            if ((float) ($l['grams'] ?? 0) <= 0) {
                continue;
            }
            $p = CustomizeOrderPricing::priceLine((string) ($l['material'] ?? 'gold'), (float) $l['grams']);
            $cross += $p['net'];
            $gst += $p['gst'];
            $count++;
        }
        $grand = round($cross + $gst, 2);
        $coin = $get('customer_mode') === 'new'
            ? 0.0
            : (float) app(SalesReturnService::class)->quote($get('coin_product_id') ? (int) $get('coin_product_id') : null, (float) $get('coin_qty'))['value'];
        $paid = round((float) $get('pay_cash') + (float) $get('pay_wallet'), 2);

        return [
            'count' => $count, 'cross' => round($cross, 2), 'gst' => round($gst, 2), 'grand' => $grand,
            'coin' => $coin, 'due' => round(max(0, $grand - $coin), 2), 'paid' => $paid,
        ];
    }

    protected function coinCredit(Get $get): HtmlString
    {
        $q = app(SalesReturnService::class)->quote($get('coin_product_id') ? (int) $get('coin_product_id') : null, (float) $get('coin_qty'));
        if ($q['value'] <= 0) {
            return new HtmlString('<span class="text-gray-500">—</span>');
        }

        return new HtmlString(sprintf(
            '<span class="font-semibold">₹%s</span> <span class="text-xs text-gray-500">(%s g %s × ₹%s/g)</span>',
            Money::group($q['value']), rtrim(rtrim(number_format($q['grams'], 4), '0'), '.'), $q['material'], Money::group($q['rate'])
        ));
    }

    protected function summary(Get $get): HtmlString
    {
        $t = $this->totals($get);
        $rows = [
            ['Items', (string) $t['count']],
            ['Sub total', Money::inr($t['cross'])],
            ['GST', Money::inr($t['gst'])],
            ['Total amount', Money::inr($t['grand'])],
            ['Coins credit', '− ' . Money::inr($t['coin'])],
            ['Amount due', Money::inr($t['due'])],
        ];
        $html = '<div class="grid grid-cols-3 md:grid-cols-6 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $strong = in_array($k, ['Total amount', 'Amount due'], true);
            $html .= '<div><div class="text-gray-500">' . e(__($k)) . '</div><div class="font-semibold ' . ($strong ? 'text-lg text-primary-600' : 'text-base') . '">' . e($v) . '</div></div>';
        }

        return new HtmlString($html . '</div>');
    }

    protected function paymentStatus(Get $get): HtmlString
    {
        $t = $this->totals($get);
        if ($t['grand'] <= 0) {
            return new HtmlString('<span class="text-gray-500 text-sm">Add items first.</span>');
        }
        $diff = round($t['paid'] - $t['due'], 2);

        // Each amount must also be COVERED by its balance right now — the server refuses
        // otherwise, so never show green for money the branch does not hold.
        $cash = (float) $get('pay_cash');
        $wallet = (float) $get('pay_wallet');
        $cashHeld = BranchOrderService::cashStockBalance((int) $get('branch_id'));
        $walletHeld = (float) (Branch::find((int) $get('branch_id'))?->digi_cash_balance ?? 0);
        $short = [];
        if ($cash > $cashHeld + 0.009) {
            $short[] = 'cash stock holds only ₹' . Money::group($cashHeld) . ' (you entered ₹' . Money::group($cash) . ')';
        }
        if ($wallet > $walletHeld + 0.009) {
            $short[] = 'branch wallet holds only ₹' . Money::group($walletHeld) . ' (you entered ₹' . Money::group($wallet) . ')';
        }
        if ($short) {
            return new HtmlString('<span style="color:#dc2626;font-weight:600">Not enough balance — ' . e(implode('; ', $short)) . '. Split the payment or top up first.</span>');
        }

        if (abs($diff) <= 0.009) {
            return new HtmlString('<span style="color:#16a34a;font-weight:600">✓ Fully paid — ₹' . Money::group($t['paid']) . ' covers the amount due. The order will proceed to your supplier.</span>');
        }
        if ($diff < 0) {
            return new HtmlString('<span style="color:#dc2626;font-weight:600">Short by ₹' . Money::group(-$diff) . ' — enter the balance from cash stock or the branch wallet. The order proceeds only when fully paid.</span>');
        }

        return new HtmlString('<span style="color:#dc2626;font-weight:600">₹' . Money::group($diff) . ' more than the amount due — reduce the amounts.</span>');
    }

    // ── Submit ────────────────────────────────────────────────────────

    public function save(): void
    {
        if (! OrderForm::orderingOpen() && ! OrderForm::windowExempt()) {
            Notification::make()->warning()
                ->title('Ordering window closed')
                ->body('Orders can be placed only between 9:00 AM and 9:00 PM.')
                ->send();

            return;
        }

        $data = $this->form->getState();

        try {
            $request = app(BranchOrderService::class)->submitCustomize([
                'branch_id' => $data['branch_id'],
                'requested_by' => auth()->id(),
                'lines' => $data['lines'] ?? [],
                'customer_mode' => $data['customer_mode'] ?? 'existing',
                'member_id' => $data['member_id'] ?? null,
                'customer' => $data['customer'] ?? [],
                'coin_qty' => ($data['customer_mode'] ?? 'existing') === 'new' ? 0 : ($data['coin_qty'] ?? 0),
                'coin_product_id' => $data['coin_product_id'] ?? null,
                'coin_collect_on' => $data['coin_collect_on'] ?? null,
                'pay_cash' => $data['pay_cash'] ?? 0,
                'pay_wallet' => $data['pay_wallet'] ?? 0,
                'payment_remarks' => $data['payment_remarks'] ?? null,
                'attachments' => $data['attachments'] ?? [],
                'attachment_names' => $data['attachment_names'] ?? [],
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->danger()->title('Order not submitted')->body($e->getMessage())->persistent()->send();

            return;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Order not submitted')
                ->body('Something went wrong while submitting — nothing was saved. Please try again.')->send();

            return;
        }

        $body = $request->request_no . ' for ' . $request->customerName() . ' is fully paid (₹' . Money::group((float) $request->paid_amount)
            . ') and sent to your supplier for approval.';
        if ($request->sales_return_id) {
            $body .= ' Sales return ' . $request->salesReturn?->return_no . ' raised for the customer\'s coins — mark it collected once you have them.';
        }
        Notification::make()->success()->title('Customize order submitted')->body($body)->persistent()->send();

        $this->form->fill($this->blankState());
    }
}
