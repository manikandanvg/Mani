<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Stock;
use App\Services\Aadhaar\AadhaarVerifier;
use App\Services\Pan\PanVerifier;
use App\Services\SalesService;
use App\Support\Translatable;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Trade 2.3 Sales — the front-desk billing screen. Three zones (customer, billing
 * details, cart + plan) culminating in "Generate Invoice", which hands a clean
 * payload to SalesService::generateInvoice().
 */
class Sales extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use \App\Filament\Concerns\HiddenFromSupport;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'Sales (Billing)';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sales';

    public ?array $data = [];

    public ?array $panResult = null;

    public ?array $aadhaarResult = null;

    public ?string $aadhaarOtpRef = null;

    public function mount(): void
    {
        $this->form->fill([
            'mode' => 'new',
            'branch_id' => auth()->user()?->branch_id ?? Branch::min('id'),
            'bill_date' => now()->toDateString(),
            'payment_type' => 'cash',
            'cart' => [],
            // Default the dialling code to India (explicit fill skips ->default()).
            'customer' => ['phone_country_code' => '+91'],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            // live metal-rate ticker across the top (POS style)
            Placeholder::make('live_rate')->label('')->columnSpanFull()
                ->content(fn () => self::liveRateBanner()),

            Grid::make(6)->schema([
                // ---------- Zone A: distributor (buying member; auditor terminology) ----------
                Section::make('Distributor')
                    ->icon('heroicon-o-user-circle')
                    ->columnSpan(2)
                    ->columns(1)
                    ->schema([
                        ToggleButtons::make('mode')->label('')->inline()
                            ->options(['new' => 'New distributor', 'existing' => 'Existing distributor'])
                            ->default('new')->live(),
                        Select::make('customer.member_id')->label('Select distributor')
                            ->visible(fn (Get $get) => $get('mode') === 'existing')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Member::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('member_code', 'like', "%{$search}%"))
                                ->limit(50)->get()
                                ->mapWithKeys(fn ($m) => [$m->id => "{$m->name} ({$m->member_code})"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->name} ({$m->member_code})" : null)
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $this->fillExisting($state, $set)),
                        TextInput::make('referrer_code')->label('Referred Distributor User ID')->required()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get) => self::codeHint($get('referrer_code'))),
                        TextInput::make('upline_code')->label('Senior Distributor User ID (Placement)')->required()
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get) => self::codeHint($get('upline_code'))),
                        Select::make('payment_type')->label('Pay Mode')
                            ->options(['cash' => 'Cash', 'cheque' => 'Cheque', 'upi' => 'UPI', 'fx' => 'FX'])
                            ->default('cash')->required(),
                        Textarea::make('payment_reference')->label('Payment Information')->rows(2),
                    ]),

                // ---------- Zone B: billing details ----------
                Section::make('Address / Billing Details')
                    ->icon('heroicon-o-identification')
                    ->columnSpan(4)
                    ->columns(3)
                    ->schema([
                        TextInput::make('customer.name')->label('Name')
                            ->required(fn (Get $get) => $get('mode') === 'new'),
                        // Dialling country code — defaults to India (+91); change for foreign customers.
                        Select::make('customer.phone_country_code')->label('Code')
                            ->options(self::dialCodes())
                            ->default('+91')
                            ->searchable()
                            ->native(false)
                            // live so the phone hint + validation follow the chosen country
                            ->live(),
                        TextInput::make('customer.phone')->label('Phone')->tel()
                            ->required(fn (Get $get) => $get('mode') === 'new')
                            // Per-country digit length (India = 10). Only enforced for NEW
                            // distributors — legacy numbers on existing members must not block a bill.
                            ->helperText(fn (Get $get) => \App\Support\PhoneLength::hint($get('customer.phone_country_code') ?: '+91'))
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($get('mode') !== 'new' || blank($value)) {
                                        return;
                                    }
                                    $err = \App\Support\PhoneLength::check($get('customer.phone_country_code') ?: '+91', (string) $value);
                                    if ($err) {
                                        $fail($err);
                                    }
                                },
                            ]),
                        TextInput::make('customer.dob')->label('Date of Birth')->type('date'),
                        TextInput::make('customer.father_name')->label("Father / Husband's Name"),
                        TextInput::make('customer.city')->label('City'),
                        // Pincode auto-fills Taluka / District / State from the offline
                        // master (live India Post API as fallback). Fields stay editable.
                        TextInput::make('customer.pincode')->label('Pincode')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set) {
                                $geo = app(\App\Services\Geo\PincodeService::class)->lookup((string) $state);
                                if (! $geo) {
                                    return;
                                }
                                $set('customer.state', $geo['state']);
                                $set('customer.district', $geo['district']);
                                if (! empty($geo['talukas'])) {
                                    $set('customer.taluka', $geo['talukas'][0]);
                                }
                            })
                            ->helperText(fn (Get $get) => filled($get('customer.pincode')) && blank($get('customer.district'))
                                ? __('Taluka / District / State fill automatically for a known PIN.')
                                : null),
                        TextInput::make('customer.taluka')->label('Taluka (City)')
                            ->datalist(fn (Get $get) => \App\Models\Pincode::where('pincode', trim((string) $get('customer.pincode')))
                                ->pluck('taluka')->filter()->unique()->values()->all()),
                        TextInput::make('customer.district')->label('District'),
                        TextInput::make('customer.state')->label('State'),
                        // B2B: a GSTIN here classifies the invoice as B2B in the GSTR-1
                        // export; blank = B2C consumer sale (board 2026-08-11).
                        TextInput::make('buyer_gst')->label('Buyer GSTIN (B2B only)')
                            ->placeholder('33ABCDE1234F1Z5')->maxLength(15)
                            ->rule('regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]{3}$/i')
                            ->validationMessages(['regex' => 'That does not look like a valid 15-character GSTIN.']),
                        TextInput::make('customer.address')->label('Address')->columnSpan(2),
                        TextInput::make('customer.email')->label('Email ID')->email(),
                        TextInput::make('customer.pan')->label('PAN Number')
                            ->live(onBlur: true)
                            ->suffixAction(
                                FormAction::make('verifyPan')->icon('heroicon-m-check-badge')->label('Verify')
                                    ->action(fn (Get $get) => $this->verifyPan($get('customer.pan'), $get('customer.name'), $get('customer.dob')))
                            )
                            ->helperText(fn () => $this->panShield()),
                        TextInput::make('customer.aadhaar')->label('Aadhaar Number')
                            ->live(onBlur: true)
                            ->suffixAction(
                                FormAction::make('verifyAadhaar')->icon('heroicon-m-check-badge')
                                    ->label(fn () => app(AadhaarVerifier::class)->supportsOtp() ? 'Verify (OTP)' : 'Verify')
                                    ->modalHeading('Aadhaar OTP verification')
                                    ->modalSubmitActionLabel('Verify OTP')
                                    ->form(fn () => app(AadhaarVerifier::class)->supportsOtp()
                                        ? [TextInput::make('otp')->label('Enter the OTP sent to the Aadhaar-linked mobile')->numeric()->required()]
                                        : [])
                                    ->mountUsing(function (Get $get) {
                                        if (app(AadhaarVerifier::class)->supportsOtp()) {
                                            $this->startAadhaarOtp($get('customer.aadhaar'));
                                        }
                                    })
                                    ->action(function (array $data, Get $get) {
                                        app(AadhaarVerifier::class)->supportsOtp()
                                            ? $this->finishAadhaarOtp($data['otp'] ?? null)
                                            : $this->verifyAadhaar($get('customer.aadhaar'));
                                    })
                            )
                            ->helperText(fn () => $this->aadhaarShield()),
                        Fieldset::make('Nominee')->columns(3)->schema([
                            TextInput::make('customer.nominee_name')->label('Name'),
                            TextInput::make('customer.nominee_age')->label('Age')->numeric(),
                            TextInput::make('customer.nominee_relation')->label('Relationship'),
                        ]),
                    ]),
            ]),

            // ---------- Zone C: billing selection (cart) ----------
            Section::make('Billing Selection')
                ->icon('heroicon-o-shopping-cart')
                ->description('Select stock items available at this branch. Quantity cannot exceed stock.')
                ->schema([
                    Hidden::make('branch_id'),
                    Placeholder::make('branch_display')->label('Branch (this session)')
                        ->content(fn (Get $get) => self::branchBanner($get('branch_id'))),
                    // Customized order (board 2026-08-27): pick a delivered order → the
                    // customer and the pieces fill in at the FROZEN order price, one bill
                    // per metal under the G10 plan. The cart is locked while one is picked.
                    Select::make('custom_order')->label('Customized order to bill (optional)')
                        ->options(fn (Get $get) => self::customOrderOptions($get('branch_id')))
                        ->placeholder('— none: normal billing —')
                        ->native(false)->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $this->applyCustomOrder($state, $set))
                        ->helperText(fn (Get $get) => self::customOrderHint($get('custom_order'))),
                    Repeater::make('cart')->label('')
                        ->live()
                        ->addActionLabel('Add item')
                        ->addable(fn (Get $get) => blank($get('custom_order')))
                        ->deletable(fn (Get $get) => blank($get('custom_order')))
                        ->columns(12)
                        ->schema([
                            Hidden::make('order_line_id'),
                            Hidden::make('description'),
                            Select::make('catalog_product_id')->label('Stock (Product)')->columnSpan(4)
                                ->disabled(fn (Get $get) => filled($get('order_line_id')))
                                ->dehydrated()
                                ->options(function (Get $get) {
                                    if (filled($get('order_line_id'))) {
                                        // locked custom piece — show its label, nothing else to pick
                                        return [$get('catalog_product_id') => (string) $get('description')];
                                    }
                                    $cart = $get('../../cart') ?? [];
                                    $taken = collect($cart)->pluck('catalog_product_id')->filter()->all();
                                    // keep this row's own selection visible; hide items chosen elsewhere
                                    $taken = array_values(array_diff($taken, [$get('catalog_product_id')]));

                                    return self::stockOptions($get('../../branch_id'), $taken);
                                })
                                ->searchable()->required()->live()
                                ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::onProductPicked($state, $set, $get)),
                            // Board 2026-08-09: coins/bars sell by PIECE — operator types a
                            // count, grams follow from the product's per-piece weight.
                            TextInput::make('qty')->label('Qty (pcs)')->numeric()->columnSpan(1)
                                ->default(1)->minValue(1)->integer()->step(1)->live(onBlur: true)
                                ->readOnly(fn (Get $get) => filled($get('order_line_id')))
                                ->hidden(fn (Get $get) => $get('material') === 'cash')
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    // snap the typed value to whole pieces (rules only fire on submit)
                                    $pcs = max(1, (int) $state);
                                    $set('qty', $pcs);
                                    $unit = (float) $get('unit_weight');
                                    if ($unit > 0) {
                                        $set('weight', round($pcs * $unit, 4));
                                    }
                                }),
                            TextInput::make('weight')
                                ->label(fn (Get $get) => $get('material') === 'cash' ? 'Amount' : 'Weight (g)')
                                ->numeric()->columnSpan(2)->live(onBlur: true)
                                ->required()
                                ->rules(['numeric', 'min:0'])
                                // auto-derived from Qty × per-piece grams; only cash (an amount) is typed
                                ->readOnly(fn (Get $get) => filled($get('order_line_id')) || ($get('material') !== 'cash' && (float) $get('unit_weight') > 0))
                                // Cash lines echo the typed amount in words (EN + TA) so the
                                // operator confirms the denomination before billing.
                                ->helperText(function (Get $get) {
                                    $stock = self::stockLeftHint($get('../../branch_id'), $get('catalog_product_id'));
                                    $amt = (float) $get('weight');
                                    if ($get('material') !== 'cash' || $amt <= 0) {
                                        return $stock;
                                    }
                                    $cur = strtoupper(self::operatorBranch()?->currency_code ?: 'INR');

                                    return new HtmlString(
                                        ($stock ? e($stock) . '<br>' : '')
                                        . '<span style="color:#ab222f;font-weight:600">' . e(amount_words($amt, 'en', $cur)) . '</span><br>'
                                        . '<span style="color:#e6ad46;font-weight:600">' . e(amount_words($amt, 'ta', $cur)) . '</span>'
                                    );
                                }),
                            TextInput::make('rate')->label('Price /g')->numeric()->columnSpan(2)->live(onBlur: true)
                                // frozen order price for a custom piece — never re-priced live
                                ->readOnly(fn (Get $get) => filled($get('order_line_id'))),
                            TextInput::make('purity')->label('Purity')->columnSpan(1),
                            Placeholder::make('line_total')->label('Grand')->columnSpan(2)
                                ->content(fn (Get $get) => Number::format(self::lineCalc([
                                    'weight' => $get('weight'), 'rate' => $get('rate'),
                                    'making_charge_pct' => $get('making_charge_pct'),
                                    'wastage_charge_pct' => $get('wastage_charge_pct'),
                                    'hallmark_charge' => $get('hallmark_charge'), 'gst_pct' => $get('gst_pct'),
                                ])['grand'], precision: 2)),
                            // hidden charge carriers (prefilled from product)
                            TextInput::make('unit_weight')->hidden(),
                            TextInput::make('material')->hidden(),
                            TextInput::make('making_charge_pct')->hidden(),
                            TextInput::make('wastage_charge_pct')->hidden(),
                            TextInput::make('hallmark_charge')->hidden(),
                            TextInput::make('gst_pct')->hidden(),
                        ]),
                ]),

            // ---------- Zone D + E: summary + plan ----------
            Grid::make(6)->schema([
                Section::make('Bill Summary')->icon('heroicon-o-calculator')->columnSpan(2)->schema([
                    Placeholder::make('bill_summary')->label('')->columnSpanFull()
                        ->content(fn (Get $get) => self::billSummary($get('cart') ?? [])),
                ]),
                Section::make('Scheme')->icon('heroicon-o-gift')
                    ->columnSpan(4)
                    ->description('Schemes available for this cart value')
                    ->schema([
                        Select::make('plan_id')->label('Select Scheme')
                            ->options(fn (Get $get) => self::planOptions($get('cart') ?? [], $get('branch_id')))
                            ->live()->required()
                            ->helperText('Schemes are matched to your cart — Gold items → gold scheme, Silver → silver scheme, Cash → Digital/RD — and only those whose minimum value ≤ cart total.'),
                        Placeholder::make('plan_benefits')->label('Scheme Benefits')
                            ->content(fn (Get $get) => self::planBenefits($get('plan_id'), self::totals($get('cart') ?? []))),
                    ]),
            ]),
        ]);
    }

    // ---------------- actions ----------------

    public function generate(): void
    {
        $data = $this->form->getState();
        $totals = self::totals($data['cart'] ?? []);

        if (empty($data['cart'])) {
            Notification::make()->title('Add at least one item to the cart')->warning()->send();

            return;
        }
        if (empty($data['plan_id'])) {
            Notification::make()->title('Select a scheme')->danger()->send();

            return;
        }

        // PAN: soft warning only — never blocks billing. Stronger note above the legal
        // threshold (Rule 114B / 269ST), but the operator may still proceed.
        $threshold = (float) config('services.pan.block_threshold', 200000);
        if (($this->panResult['match'] ?? '') !== 'exact') {
            $body = $totals['grand'] >= $threshold
                ? 'This bill is ₹' . Number::format($threshold) . '+ and the PAN is not verified (Rule 114B). Saving anyway.'
                : 'PAN is not verified. Saving anyway.';
            Notification::make()->title('PAN not verified')->body($body)->warning()->send();
        }

        // enrich cart lines with computed net/grand for the service
        $data['cart'] = array_map(function ($l) {
            $c = self::lineCalc($l);
            $l['net_total'] = $c['net'];
            $l['grand_total'] = $c['grand'];
            $l['making'] = ($l['weight'] ?? 0) * ($l['rate'] ?? 0) * (($l['making_charge_pct'] ?? 0) / 100);
            $l['wastage'] = ($l['weight'] ?? 0) * ($l['rate'] ?? 0) * (($l['wastage_charge_pct'] ?? 0) / 100);

            return $l;
        }, $data['cart']);

        $rate = self::pageRate();
        if (! $rate) {   // foreign branch, no regional feed — refuse rather than misprice
            Notification::make()
                ->title('No live metal rate for this branch\'s region')
                ->body('Ask HQ to set the region\'s live rate before billing.')
                ->danger()->send();

            return;
        }
        $data['gold_rate'] = $rate->gold ?? 0;
        $data['silver_rate'] = $rate->silver ?? 0;
        $data['diamond_rate'] = $rate->diamond ?? 0;
        $data['created_by'] = auth()->id();
        if (! empty($data['custom_order'])) {
            $data['custom_order_id'] = (int) explode(':', (string) $data['custom_order'])[0];
        }

        // carry the KYC verification outcome so it is stored on the member
        $data['customer']['pan_verified'] = ($this->panResult['match'] ?? '') === 'exact';
        $data['customer']['pan_verified_name'] = $this->panResult['registered_name'] ?? null;
        $data['customer']['aadhaar_verified'] = (bool) ($this->aadhaarResult['valid'] ?? false);

        try {
            $invoice = app(SalesService::class)->generateInvoice($data);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->title('Bill not generated')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title('Sales saved successfully')
            ->body("Invoice {$invoice->invoice_no} generated. Contract will follow shortly.")
            ->success()->send();

        $this->form->fill([
            'mode' => 'new', 'branch_id' => $data['branch_id'],
            'bill_date' => now()->toDateString(), 'payment_type' => 'cash', 'cart' => [],
            'customer' => ['phone_country_code' => '+91'], 'custom_order' => null,
        ]);
        $this->panResult = null;
        $this->aadhaarResult = null;
    }

    // ---------------- customized orders (board 2026-08-27) ----------------

    /** Delivered, unbilled customized pieces at this branch — one option per order + metal. */
    protected static function customOrderOptions($branchId): array
    {
        if (! $branchId) {
            return [];
        }
        $out = [];
        foreach (app(\App\Services\CustomizeOrderService::class)->billableGroups((int) $branchId) as $key => $g) {
            $out[$key] = $g['order']->request_no . ' · ' . ucfirst($g['material']) . ' (' . $g['lines']->count() . ' pc) · ' . $g['order']->customerName();
        }

        return $out;
    }

    protected static function customOrderHint($key): ?HtmlString
    {
        if (blank($key)) {
            return null;
        }
        $order = \App\Models\BranchOrderRequest::find((int) explode(':', (string) $key)[0]);
        if (! $order) {
            return null;
        }
        $bits = ['Pieces are billed at the frozen order price under the G10 ' . explode(':', (string) $key)[1] . ' plan.'];
        if ((float) $order->quote_extra > 0) {
            $bits[] = '<b style="color:#ab222f">Head Office extra quote ₹' . \App\Support\Money::group((float) $order->quote_extra)
                . ' will be debited from your branch wallet on this bill' . ($order->quote_debited_at ? ' (already debited)' : '') . '.</b>';
        }
        if ((float) $order->coin_credit > 0) {
            $bits[] = 'Customer coins credit ₹' . \App\Support\Money::group((float) $order->coin_credit) . ' was already applied on the order.';
        }

        return new HtmlString(implode(' ', $bits));
    }

    /** Fill the customer (existing member or the captured new customer) and lock the cart to the order's pieces. */
    protected function applyCustomOrder($key, Set $set): void
    {
        if (blank($key)) {
            $set('cart', []);
            $set('plan_id', null);

            return;
        }
        [$orderId, $material] = explode(':', (string) $key) + [null, null];
        $order = \App\Models\BranchOrderRequest::with(['lines', 'member'])->find((int) $orderId);
        if (! $order) {
            return;
        }
        if ($order->member_id) {
            $set('mode', 'existing');
            $set('customer.member_id', $order->member_id);
            $this->fillExisting($order->member_id, $set);
        } else {
            $set('mode', 'new');
            $set('customer.member_id', null);
            $c = (array) $order->customer_details;
            foreach (['name', 'phone', 'email', 'address', 'city', 'pincode'] as $f) {
                $set('customer.' . $f, $c[$f] ?? null);
            }
            $set('customer.phone_country_code', '+91');
        }
        $set('cart', app(\App\Services\CustomizeOrderService::class)->cartFor($order, (string) $material));
        $set('plan_id', null);
    }

    public function verifyPan(?string $pan, ?string $name, ?string $dob = null): void
    {
        if (blank($pan)) {
            return;
        }
        $this->panResult = app(PanVerifier::class)->verify($pan, $name, $dob);
        $ok = ($this->panResult['match'] ?? '') === 'exact';
        Notification::make()
            ->title($ok ? 'PAN verified — name matches' : ($this->panResult['message'] ?? 'PAN check'))
            ->{$ok ? 'success' : 'warning'}()->send();
    }

    public function verifyAadhaar(?string $aadhaar): void
    {
        if (blank($aadhaar)) {
            return;
        }
        $this->aadhaarResult = app(AadhaarVerifier::class)->verify($aadhaar);
        $ok = (bool) ($this->aadhaarResult['valid'] ?? false);
        Notification::make()
            ->title($ok ? 'Aadhaar verified' : ($this->aadhaarResult['message'] ?? 'Aadhaar check'))
            ->{$ok ? 'success' : 'warning'}()->send();
    }

    /** OTP step 1 — sends the OTP to the Aadhaar-linked mobile (modal opens to enter it). */
    public function startAadhaarOtp(?string $aadhaar): void
    {
        $this->aadhaarOtpRef = null;
        $res = app(AadhaarVerifier::class)->sendOtp((string) $aadhaar);
        if ($res['ok'] ?? false) {
            $this->aadhaarOtpRef = $res['ref_id'];
        }
        Notification::make()
            ->title(($res['ok'] ?? false) ? 'OTP sent' : 'Could not send OTP')
            ->body($res['message'] ?? '')
            ->{($res['ok'] ?? false) ? 'success' : 'warning'}()->send();
    }

    /** OTP step 2 — verifies the OTP against the reference from step 1. */
    public function finishAadhaarOtp(?string $otp): void
    {
        if (blank($this->aadhaarOtpRef)) {
            Notification::make()->title('OTP was not sent — try again')->warning()->send();

            return;
        }
        $res = app(AadhaarVerifier::class)->verifyOtp($this->aadhaarOtpRef, (string) $otp);
        $ok = (bool) ($res['valid'] ?? false);
        $this->aadhaarResult = ['valid' => $ok, 'message' => $res['message'] ?? ''];
        if ($ok) {
            $this->aadhaarOtpRef = null;
        }
        Notification::make()
            ->title($ok ? 'Aadhaar verified via OTP' : ($res['message'] ?? 'OTP verification failed'))
            ->{$ok ? 'success' : 'warning'}()->send();
    }

    /** Green shield + text under the PAN field once verified (or a red/amber state). */
    protected function panShield(): ?HtmlString
    {
        if (! $this->panResult) {
            return null;
        }

        return match ($this->panResult['match'] ?? '') {
            'exact' => self::kycShield('ok', 'PAN verified — name matches'),
            'partial' => self::kycShield('warn', 'Partial match: ' . ($this->panResult['registered_name'] ?? '')),
            default => self::kycShield('fail', $this->panResult['message'] ?? 'Not verified'),
        };
    }

    protected function aadhaarShield(): ?HtmlString
    {
        if (! $this->aadhaarResult) {
            return null;
        }

        return ($this->aadhaarResult['valid'] ?? false)
            ? self::kycShield('ok', 'Aadhaar verified')
            : self::kycShield('fail', $this->aadhaarResult['message'] ?? 'Not verified');
    }

    /** Shield icon + label, coloured by state (ok=green, warn=amber, fail=crimson). */
    protected static function kycShield(string $state, string $text): HtmlString
    {
        $color = ['ok' => '#16a34a', 'warn' => '#d97706', 'fail' => '#ab222f'][$state] ?? '#6b7280';
        $badge = '<svg viewBox="0 0 20 20" fill="currentColor" width="15" height="15" style="flex:none">'
            . '<path fill-rule="evenodd" d="M16.403 12.652a3 3 0 000-5.304 3 3 0 00-3.751-3.751 3 3 0 00-5.304 0 3 3 0 00-3.751 3.75 3 3 0 000 5.305 3 3 0 003.75 3.751 3 3 0 005.305 0 3 3 0 003.751-3.75zm-2.546-4.46a.75.75 0 00-1.214-.883l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>';
        $mark = $state === 'ok' ? $badge : '<span style="font-weight:700">' . ($state === 'warn' ? '⚠' : '✗') . '</span>';

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:.3rem;color:' . $color . ';font-weight:600">'
            . $mark . '<span>' . e($text) . '</span></span>'
        );
    }

    // ---------------- helpers ----------------

    protected function fillExisting($id, Set $set): void
    {
        // selecting a different customer clears any prior verification shields
        $this->panResult = null;
        $this->aadhaarResult = null;

        $m = $id ? Member::with(['referrer', 'upline'])->find($id) : null;
        if (! $m) {
            return;
        }
        $set('customer.name', $m->name);
        $set('customer.dob', $m->dob?->format('Y-m-d'));
        $set('customer.father_name', $m->father_name);
        $set('customer.phone', $m->phone);
        $set('customer.phone_country_code', $m->phone_country_code ?: '+91');
        $set('customer.address', $m->address);
        $set('customer.city', $m->city);
        $set('customer.pincode', $m->pincode);
        $set('customer.taluka', $m->taluka);
        $set('customer.district', $m->district);
        $set('customer.state', $m->state);
        $set('customer.email', $m->email);
        $set('customer.pan', $m->pan);
        $set('customer.aadhaar', $m->aadhaar);
        $set('customer.nominee_name', $m->nominee_name);
        $set('customer.nominee_age', $m->nominee_age);
        $set('customer.nominee_relation', $m->nominee_relation);
        // The member's existing sponsor/placement chain populates the UID fields.
        $set('referrer_code', $m->referrer?->member_code);
        $set('upline_code', $m->upline?->member_code);

        // restore stored KYC verification so the shields show without re-verifying
        if ($m->pan_verified) {
            $this->panResult = ['valid' => true, 'match' => 'exact',
                'registered_name' => $m->pan_verified_name, 'message' => 'PAN verified'];
        }
        if ($m->aadhaar_verified) {
            $this->aadhaarResult = ['valid' => true, 'message' => 'Aadhaar verified'];
        }
    }

    /**
     * Phone dialling codes for billing. India (+91) is the default; a practical spread
     * of common countries covers the walk-in range. The stored value is the dial code.
     */
    protected static function dialCodes(): array
    {
        return \App\Support\DialCodes::options();
    }

    protected static function codeHint(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }
        $m = Member::where('member_code', strtoupper(trim($code)))->first();

        return $m ? "✓ {$m->name}" : '✗ Entered UID does not exist. Please try another.';
    }

    protected static function branchBanner($branchId): HtmlString
    {
        $b = $branchId ? Branch::find($branchId) : null;
        if (! $b) {
            return new HtmlString('<span style="color:#ab222f;font-weight:600">No branch assigned to your login</span>');
        }

        $addr = collect([$b->address, $b->city, $b->pincode])->filter()->implode(', ');
        $meta = collect([
            $b->incharge ? 'Incharge: ' . e($b->incharge) : null,
            $b->phone ? 'Ph: ' . e($b->phone) : null,
            $b->gst_no ? 'GSTN: ' . e($b->gst_no) : null,
        ])->filter()->implode(' &nbsp;·&nbsp; ');

        return new HtmlString(
            '<div style="display:flex;align-items:flex-start;gap:.5rem">'
            . '<span style="width:.55rem;height:.55rem;border-radius:9999px;background:#16a34a;display:inline-block;margin-top:.35rem;flex:none"></span>'
            . '<div style="line-height:1.35">'
            . '<div style="font-weight:700;color:#111827">' . e($b->name) . '</div>'
            . ($addr ? '<div style="font-size:.8rem;color:#6b7280">' . e($addr) . '</div>' : '')
            . ($meta ? '<div style="font-size:.75rem;color:#6b7280;margin-top:.1rem">' . $meta . '</div>' : '')
            . '</div></div>'
        );
    }

    /** The operator's branch — the currency + rate-region authority for this sale. */
    protected static function operatorBranch(): ?Branch
    {
        $branchId = auth()->user()?->branch_id;

        return $branchId ? Branch::find($branchId) : null;
    }

    /**
     * The metal rate feed for the operator's REGION (HQ / no branch = India).
     * A FOREIGN-currency branch with no regional rate row gets NULL — never the
     * India feed: INR-magnitude rates under a foreign symbol would price bills
     * wrong by the whole fx factor. generate() refuses to bill on null.
     */
    protected static function pageRate(): ?LiveRate
    {
        $branch = self::operatorBranch();
        $regional = $branch ? LiveRate::forBranch($branch) : null;
        if ($regional) {
            return $regional;
        }

        return strtoupper($branch?->currency_code ?: 'INR') === 'INR' ? LiveRate::latestFor('IN') : null;
    }

    /** The operator's currency symbol (₹ for India / no branch). */
    protected static function sym(): string
    {
        return self::operatorBranch()?->currencySymbol() ?: '₹';
    }

    protected static function liveRateBanner(): HtmlString
    {
        $r = self::pageRate();
        if (! $r) {
            return new HtmlString('<span style="color:#ab222f">Live rate not set.</span>');
        }

        $g = Number::format((float) $r->gold, precision: 2);
        $s = Number::format((float) $r->silver, precision: 2);
        $d = Number::format((float) $r->diamond, precision: 2);

        $sym = self::sym();
        $chip = fn (string $label, string $val, string $color) => '<span style="margin:0 1.75rem;font-weight:600">'
            . '<span style="color:' . $color . '">● ' . $label . '</span> '
            . '<span style="color:#fff">' . $sym . $val . '/g</span></span>';

        $segment = $chip('GOLD', $g, '#e6ad46') . $chip('SILVER', $s, '#cbd5e1') . $chip('DIAMOND', $d, '#7dd3fc')
            . '<span style="margin:0 1.75rem;color:#9ca3af">Live Price (per gram)</span>';

        // Seamless marquee: two copies translated -50% so it loops without a gap.
        $html = '<div style="overflow:hidden;white-space:nowrap;border-radius:.75rem;'
            . 'background:linear-gradient(90deg,#8a1b26,#ab222f);padding:.6rem 0;box-shadow:inset 0 0 12px rgba(0,0,0,.25)">'
            . '<div style="display:inline-block;animation:icl-ticker 22s linear infinite">'
            . '<span style="display:inline-block">' . $segment . '</span>'
            . '<span style="display:inline-block">' . $segment . '</span>'
            . '</div></div>'
            . '<style>@keyframes icl-ticker{from{transform:translateX(0)}to{transform:translateX(-50%)}}</style>';

        return new HtmlString($html);
    }

    protected static function stockOptions($branchId, array $exclude = []): array
    {
        if (! $branchId) {
            return [];
        }
        $default = Translatable::defaultLocale();

        return Stock::with('catalogProduct')->where('branch_id', $branchId)->where('quantity', '>', 0)
            ->where('order_line_id', 0)   // custom pieces bill only via the customized-order picker
            ->when($exclude, fn ($q) => $q->whereNotIn('catalog_product_id', $exclude))
            ->get()
            ->mapWithKeys(function ($s) use ($default) {
                $p = $s->catalogProduct;
                if (! $p) {
                    return [];
                }
                $name = Translatable::pick($p->name, $default);

                // stock.quantity = PIECES (cash = ₹). Show pieces plus the gram
                // equivalent: "85 pcs ≈ 42,500 g".
                $qty = (float) $s->quantity;
                $pcs = rtrim(rtrim(number_format($qty, 4), '0'), '.');
                $avail = $p->material === 'cash'
                    ? \App\Support\Money::inr($qty) . ' balance'
                    : $pcs . ' pcs' . ((float) $p->default_weight > 0
                        ? ' ≈ ' . number_format($p->gramsFromPieces($qty), 3) . ' g'
                        : '') . ' in stock';

                return [$p->id => "{$name} [{$p->code}] · {$avail}"];
            })->all();
    }

    protected static function stockLeftHint($branchId, $productId): ?string
    {
        if (! $branchId || ! $productId) {
            return null;
        }
        $q = Stock::where('branch_id', $branchId)->where('catalog_product_id', $productId)->value('quantity');
        if ($q === null) {
            return null;
        }

        // stock.quantity = PIECES (cash = ₹); show grams as the derived figure.
        $cp = CatalogProduct::find($productId);
        if ($cp?->material === 'cash') {
            return 'Available: ₹' . \App\Support\Money::group((float) $q);
        }
        $pcs = rtrim(rtrim(number_format((float) $q, 4), '0'), '.');
        $grams = (float) ($cp?->default_weight ?? 0) > 0
            ? ' (≈ ' . number_format($cp->gramsFromPieces((float) $q), 3) . ' g)'
            : '';

        return "Available: {$pcs} pcs{$grams}";
    }

    protected static function onProductPicked($id, Set $set, Get $get): void
    {
        $p = CatalogProduct::find($id);
        if (! $p) {
            return;
        }
        $rate = self::pageRate();
        $set('material', $p->material);
        $set('purity', $p->default_purity);
        $set('making_charge_pct', $p->making_charge_pct);
        $set('wastage_charge_pct', $p->wastage_charge_pct);
        $set('hallmark_charge', $p->hallmark_charge);
        $set('gst_pct', $p->gst_pct);

        // Cash is a rupee balance: 1 unit = ₹1, no live-metal rate, no charges/GST.
        // Weight (= the cash/contract amount) is entered by the operator, so leave blank.
        if ($p->material === 'cash') {
            $set('rate', 1);
            $set('unit_weight', null);
            $set('qty', null);
            $set('weight', null);

            return;
        }

        // Piece-count model: qty resets to 1, grams = qty × the product's per-piece weight.
        $set('unit_weight', $p->default_weight);
        $set('qty', 1);
        $set('weight', $p->default_weight);
        $set('rate', $p->material === 'silver' ? ($rate?->silver ?? 0) : ($rate?->gold ?? 0));
    }

    /** Per-line: weight×rate + (making+wastage)% + hallmark, then GST. */
    protected static function lineCalc(array $l): array
    {
        $base = (float) ($l['weight'] ?? 0) * (float) ($l['rate'] ?? 0);
        $charges = $base * (((float) ($l['making_charge_pct'] ?? 0) + (float) ($l['wastage_charge_pct'] ?? 0)) / 100)
            + (float) ($l['hallmark_charge'] ?? 0);
        $net = $base + $charges;
        $gst = $net * ((float) ($l['gst_pct'] ?? 0) / 100);

        return ['net' => round($net, 2), 'gst' => round($gst, 2), 'grand' => round($net + $gst, 2)];
    }

    protected static function billSummary(array $cart): HtmlString
    {
        $t = self::totals($cart);
        $items = count($cart);
        $weight = round(array_sum(array_map(fn ($l) => (float) ($l['weight'] ?? 0), $cart)), 3);

        // Optional foreign-currency reference. The bill itself is INR (live gold rates and
        // the generated tax invoice are in rupees); we only ADD an approximate equivalent.
        $fxCode = \App\Support\Money::current();
        $baseCode = \App\Support\Money::base()?->code ?? 'INR';
        $fxLine = '';
        if (strtoupper($fxCode) !== strtoupper($baseCode) && $t['grand'] > 0) {
            $sym = \App\Support\Money::currency($fxCode)?->symbol ?? '';
            $conv = \App\Support\Money::convert((float) $t['grand'], $fxCode);
            $fxLine =
                '<div style="display:flex;flex-wrap:wrap;gap:.25rem .75rem;justify-content:space-between;padding:.45rem .9rem;background:#fff;font-size:.78rem;color:#6b7280">'
                . '<span>Approx. in ' . strtoupper($fxCode) . '</span>'
                . '<span style="font-weight:700;color:#111827;font-variant-numeric:tabular-nums">' . $sym . ' ' . Number::format($conv, 2) . '</span></div>';
        }

        $row = fn (string $k, string $v, string $extra = '') =>
            '<div style="display:flex;justify-content:space-between;padding:.45rem .1rem;border-bottom:1px dashed #e5e7eb;' . $extra . '">'
            . '<span style="color:#6b7280">' . $k . '</span>'
            . '<span style="font-weight:600;color:#111827;font-variant-numeric:tabular-nums">' . $v . '</span></div>';

        $chip = fn (string $label, string $val) =>
            '<div style="flex:1;background:#fff;border:1px solid #e5e7eb;border-radius:.6rem;padding:.5rem .6rem;text-align:center">'
            . '<div style="font-size:.6rem;letter-spacing:.04em;color:#9ca3af">' . $label . '</div>'
            . '<div style="font-weight:700;color:#111827;font-size:.95rem">' . $val . '</div></div>';

        $html =
            '<div style="border:1px solid #e5e7eb;border-radius:.85rem;overflow:hidden;background:#fafafa">'
            . '<div style="display:flex;gap:.5rem;padding:.7rem">'
            . $chip('ITEMS', (string) $items)
            . $chip('TOTAL WEIGHT/QTY', Number::format($weight, 3) . ' g')
            . '</div>'
            . '<div style="padding:0 .9rem .3rem">'
            . $row('Gross Total', self::sym() . ' ' . Number::format($t['gross'], 2))
            . $row('GST', self::sym() . ' ' . Number::format($t['gst'], 2))
            . '</div>'
            // grand total band
            . '<div style="background:linear-gradient(90deg,#8a1b26,#ab222f);color:#fff;padding:.7rem .9rem;'
            . 'display:flex;justify-content:space-between;align-items:center">'
            . '<span style="font-weight:600;letter-spacing:.03em">GRAND TOTAL</span>'
            . '<span style="font-weight:800;font-size:1.35rem;font-variant-numeric:tabular-nums">' . self::sym() . ' ' . Number::format($t['grand'], 2) . '</span>'
            . '</div>'
            . $fxLine
            // amount-in-words is an INR (Indian-numbering) formatter — INR bills only
            . (strtoupper(self::operatorBranch()?->currency_code ?: 'INR') === 'INR'
                ? '<div style="padding:.5rem .9rem;font-size:.72rem;color:#6b7280;background:#fff">'
                    . '<span style="color:#e6ad46;font-weight:700">In words:</span> ' . ($t['grand'] > 0 ? e(inr_words($t['grand'])) : '—')
                    . '</div>'
                : '')
            . '</div>';

        return new HtmlString($html);
    }

    protected static function totals(array $cart): array
    {
        $gross = $gst = 0.0;
        foreach ($cart as $l) {
            $c = self::lineCalc($l);
            $gross += $c['net'];
            $gst += $c['gst'];
        }

        return ['gross' => round($gross, 2), 'gst' => round($gst, 2), 'grand' => round($gross + $gst, 2)];
    }

    protected static function planOptions(array $cart, $branchId = null): array
    {
        // Plan min/max ceilings are INR base; the cart totals are in the branch
        // currency — convert once so the filter agrees with assertPlanCaps.
        $fx = Branch::find($branchId)?->fxRateToBase() ?? 1.0;
        $grand = round(self::totals($cart)['grand'] * $fx, 2);
        $types = self::planTypesForCart($cart);

        return Plan::where('is_active', true)
            ->where('min_value', '<=', $grand)
            ->where(fn ($q) => $q->whereNull('max_value')->orWhere('max_value', '>=', $grand))
            ->when($types !== null, fn ($q) => $q->whereIn('type', $types))
            ->get()
            ->mapWithKeys(function ($p) {
                $name = Translatable::pick($p->name) ?: $p->code;

                return [$p->id => "{$p->code} — {$name}"];
            })->all();
    }

    /**
     * Map the cart's item materials to the plan `type`s allowed for it:
     *   gold → gold (P206) · silver → silver (P211) · cash → digital + rd.
     * Returns null when the cart is empty (no type restriction yet).
     */
    protected static function planTypesForCart(array $cart): ?array
    {
        $materials = collect($cart)->pluck('material')->filter()
            ->map(fn ($m) => strtolower((string) $m))->unique();

        if ($materials->isEmpty()) {
            return null;
        }

        $map = ['gold' => ['gold'], 'silver' => ['silver'], 'cash' => ['digital', 'rd']];
        $types = $materials->flatMap(fn ($m) => $map[$m] ?? [])->unique()->values()->all();

        // unknown material(s) only → restrict to nothing rather than show everything
        return $types ?: ['__none__'];
    }

    protected static function planBenefits($planId, array $totals): HtmlString
    {
        $p = $planId ? Plan::find($planId) : null;
        if (! $p) {
            return new HtmlString('<div style="color:#6b7280;padding:.5rem">Select a scheme to see benefits.</div>');
        }

        $gross = (float) $totals['gross'];
        $allocation = round($gross * (float) $p->allocation_bv / 100, 2);

        // CB coupon (cashback)
        $cbTotal = round($allocation * (float) $p->cbc_value / 100, 2);
        $cbCount = (int) $p->cbc_count;
        $cbMonthly = $cbCount > 0 ? round($cbTotal / $cbCount, 2) : 0.0;

        $ic = array_map('floatval', $p->ic_schedule ?? []);
        $lvl = array_map('floatval', $p->level_schedule ?? []);
        $icTotal = round(array_sum(array_map(fn ($pct) => $allocation * $pct / 100, $ic)), 2);
        $lvlTotal = round(array_sum(array_map(fn ($pct) => $allocation * $pct / 100, $lvl)), 2);
        $lvlMonths = (int) ($p->level_com_duration ?: 0);

        $pctTxt = fn ($pct) => rtrim(rtrim(number_format((float) $pct, 2), '0'), '.');

        // per-level grid of (ordinal, %, amount)
        $grid = function (array $sched) use ($allocation, $pctTxt) {
            if (! $sched) {
                return '<div style="color:#9ca3af;font-size:.75rem">—</div>';
            }
            $ord = ['1ST', '2ND', '3RD', '4TH', '5TH', '6TH', '7TH', '8TH', '9TH', '10TH', '11TH', '12TH'];
            $cells = '';
            foreach ($sched as $i => $pct) {
                $amt = round($allocation * (float) $pct / 100, 2);
                $dim = (float) $pct <= 0 ? 'opacity:.45;' : '';
                $cells .= '<div style="' . $dim . 'background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem .4rem;text-align:center">'
                    . '<div style="font-size:.6rem;color:#9ca3af;letter-spacing:.02em">' . ($ord[$i] ?? ($i + 1)) . ' · ' . $pctTxt($pct) . '%</div>'
                    . '<div style="font-weight:700;font-size:.8rem;color:#111827">' . self::sym() . Number::format($amt, 2) . '</div></div>';
            }

            // auto-fit: 5 across on a desktop, 2–3 across on a phone (board phase 2: responsive).
            return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(6.5rem,1fr));gap:.35rem;margin-top:.4rem">' . $cells . '</div>';
        };

        $card = fn (string $title, string $total, string $sub, string $body, string $accent) =>
            '<div style="border:1px solid #e5e7eb;border-left:4px solid ' . $accent . ';border-radius:.75rem;padding:.75rem .9rem;background:#fafafa">'
            . '<div style="display:flex;justify-content:space-between;align-items:baseline">'
            . '<span style="font-weight:700;color:#111827">' . $title . '</span>'
            . '<span style="font-weight:800;color:' . $accent . '">' . $total . '</span></div>'
            . ($sub ? '<div style="font-size:.7rem;color:#6b7280;margin-top:.1rem">' . $sub . '</div>' : '')
            . $body . '</div>';

        $sym = self::sym();
        $head = '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem">'
            . '<div><div style="font-size:.65rem;color:#9ca3af">ALLOCATION (' . $pctTxt($p->allocation_bv) . '%)</div>'
            . '<div style="font-weight:800;color:#ab222f;font-size:1.05rem">' . $sym . Number::format($allocation, 2) . '</div></div>'
            . '<div><div style="font-size:.65rem;color:#9ca3af">PAID + GST</div>'
            . '<div style="font-weight:800;color:#111827;font-size:1.05rem">' . $sym . Number::format((float) $totals['grand'], 2) . '</div></div></div>';

        $cb = $card('Promotional Incentive (CBC)', $sym . Number::format($cbTotal, 2),
            $cbCount > 0
                ? 'Cashback ' . $pctTxt($p->cbc_value) . '% · ' . $sym . Number::format($cbMonthly, 2) . '/month × ' . $cbCount . ' months'
                : 'No cashback coupon on this scheme',
            '', '#16a34a');

        $icCard = $card('Sales Incentive (IC)', $sym . Number::format($icTotal, 2),
            'Instant · paid one-time across up to ' . count(array_filter($ic)) . ' placement layers', $grid($ic), '#e6ad46');

        $lvlCard = $card('Turnover-based Salary (GAP)', $sym . Number::format($lvlTotal, 2),
            $lvlMonths > 0 ? 'Monthly · over ' . $lvlMonths . ' months' : 'Monthly recurring', $grid($lvl), '#0ea5e9');

        return new HtmlString('<div style="display:flex;flex-direction:column;gap:.6rem">' . $head . $cb . $icCard . $lvlCard . '</div>');
    }
}
