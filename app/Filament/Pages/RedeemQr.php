<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\RedeemableQr;
use App\Services\Redeem\RedemptionService;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\Translatable;
use Filament\Forms\Components\Actions as FormActions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Redeem QR — the operator-facing screen for the actual sale against a stock QR
 * (legacy admin/trade/redeeminvqr). Flow: scan/enter the QR + redeeming branch →
 * Fetch (contract + worth + mode) → build the cart (Mode A is auto from the original
 * sale, Mode B is a manual catalog pick priced live) → Send OTP over WhatsApp → enter
 * OTP, optionally tick "Open Distributor A/C" (eligible plans only) → Redeem.
 *
 * All money/stock logic lives in RedemptionService; this page only collects input.
 */
class RedeemQr extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Redeem QR';

    protected static ?string $title = 'Redeem Stock QR';

    protected static string $view = 'filament.pages.redeem-qr';

    /** Form state. */
    public ?array $data = [];

    /** Fetched, serialisable context summary (null until a QR is fetched). */
    public ?array $ctx = null;

    public function mount(): void
    {
        $this->form->fill([
            'branch_id' => $this->defaultBranchId(),
            'lines' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('1. Scan the Redeemable QR')
                    ->icon('heroicon-o-qr-code')
                    ->columns(2)
                    ->schema([
                        TextInput::make('qr_code')
                            ->label('QR code')
                            ->placeholder('Scan or type the QR token')
                            ->autofocus()
                            ->disabled(fn () => $this->ctx !== null)
                            ->dehydrated()   // keep the value in state even when disabled (post-fetch)
                            ->required(),
                        Select::make('branch_id')
                            ->label('Redeeming branch')
                            ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->disabled(fn () => $this->ctx !== null || $this->isDistributor())
                            ->dehydrated()   // keep the value in state even when disabled
                            ->required()
                            ->helperText('Mode-A (metal) plans can be redeemed only at the billing branch.'),
                        FormActions::make([
                            FormAction::make('fetch')
                                ->label('Fetch contract')
                                ->icon('heroicon-o-magnifying-glass')
                                ->visible(fn () => $this->ctx === null)
                                ->action('fetch'),
                            FormAction::make('reset')
                                ->label('Start over')
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->visible(fn () => $this->ctx !== null)
                                ->action('resetForm'),
                        ])->columnSpanFull(),
                    ]),

                Section::make('2. Contract')
                    ->icon('heroicon-o-identification')
                    ->visible(fn () => $this->ctx !== null)
                    ->columns(3)
                    ->schema([
                        Placeholder::make('member')->label('Distributor')
                            ->content(fn () => $this->ctx['member'] ?? '—'),
                        Placeholder::make('plan')->label('Scheme')
                            ->content(fn () => $this->ctx['plan'] ?? '—'),
                        Placeholder::make('worth')->label('QR worth')
                            ->content(fn () => new HtmlString('<span class="text-lg font-bold text-primary-600">₹' . number_format((float) ($this->ctx['worth'] ?? 0), 2) . '</span>')),
                        Placeholder::make('mode')->label('Mode')
                            ->content(fn () => ($this->ctx['mode'] ?? '') === 'A'
                                ? new HtmlString('<span class="font-semibold">A — Metal purchase</span> <span class="text-gray-500">(cart from original sale · billing branch only · no stock/margin)</span>')
                                : new HtmlString('<span class="font-semibold">B — Stock redemption</span> <span class="text-gray-500">(pick items ≥ worth · branch stock & GM margin)</span>')),
                    ]),

                // Mode A: read-only cart rebuilt from the original sale lines.
                Section::make('3. Items (from the original sale)')
                    ->icon('heroicon-o-lock-closed')
                    ->visible(fn () => $this->ctx !== null && ($this->ctx['mode'] ?? '') === 'A')
                    ->schema([
                        Placeholder::make('cartA')
                            ->hiddenLabel()
                            ->content(fn () => new HtmlString($this->renderModeACart())),
                    ]),

                // Mode B: operator picks catalog items priced at the live rate.
                Section::make('3. Items to redeem')
                    ->icon('heroicon-o-shopping-cart')
                    ->visible(fn () => $this->ctx !== null && ($this->ctx['mode'] ?? '') === 'B')
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel('Add item')
                            ->reorderable(false)
                            ->columns(12)
                            ->live()
                            ->schema([
                                Select::make('catalog_product_id')
                                    ->label('Catalog item')
                                    ->options(fn () => $this->catalogOptions())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $cp = $state ? CatalogProduct::find($state) : null;
                                        if ($cp) {
                                            $set('unit_weight', (float) $cp->default_weight);
                                        }
                                    })
                                    ->columnSpan(5),
                                TextInput::make('unit_weight')->label('Weight (g)')
                                    ->numeric()->default(0)->live(onBlur: true)->columnSpan(3),
                                TextInput::make('quantity')->label('Qty')
                                    ->numeric()->default(1)->live(onBlur: true)->columnSpan(2),
                                Placeholder::make('line_total')->label('Line ₹')
                                    ->content(fn (Get $get) => '₹' . number_format($this->priceRow($get('catalog_product_id'), (float) $get('unit_weight'), (float) $get('quantity')), 2))
                                    ->columnSpan(2),
                            ]),
                        Placeholder::make('cart_total')
                            ->label('Cart total')
                            ->content(fn (Get $get) => new HtmlString($this->renderCartTotal($get('lines') ?? []))),
                    ]),

                Section::make('4. Verify & Redeem')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn () => $this->ctx !== null)
                    ->columns(2)
                    ->schema([
                        FormActions::make([
                            FormAction::make('sendOtp')
                                ->label('Send OTP on WhatsApp')
                                ->icon('heroicon-o-chat-bubble-left-right')
                                ->color('success')
                                ->action('sendOtp'),
                        ])->columnSpanFull(),
                        TextInput::make('otp')
                            ->label('OTP')
                            ->placeholder('5-digit code sent to the distributor'),
                        TextInput::make('buyer_gst')
                            ->label('Distributor GST (optional)')
                            ->placeholder('e.g. 33ABCDE1234F1Z5 — prints in "Invoice To"')
                            ->helperText('Shown on the tax invoice. If a dealer account is opened, this GSTIN is also set on the dealer branch.')
                            ->maxLength(25),

                        // Opt-in dealer account — eligible plans only, default OFF.
                        Section::make()
                            ->visible(fn () => (bool) ($this->ctx['dealer_eligible'] ?? false))
                            ->extraAttributes(['class' => 'redeem-dealer-box'])
                            ->columnSpanFull()
                            ->schema([
                                Checkbox::make('create_dealer')
                                    ->label('Open a Dealer account for this distributor')
                                    ->helperText('Only tick if this distributor should become a dealer. Creates a branch + login seeded with the redeemed stock (using the Distributor GST above). Leave unticked for a plain redemption.')
                                    ->extraAttributes(['class' => 'redeem-dealer-toggle'])
                                    ->live(),
                                TextInput::make('dealer_branch_name')
                                    ->label('Dealer branch name')
                                    ->visible(fn (Get $get) => (bool) $get('create_dealer'))
                                    ->placeholder('e.g. <Distributor name> — Dealer'),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /** Page-level submit. */
    protected function getFormActions(): array
    {
        return [];
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function fetch(): void
    {
        $state = $this->form->getState();
        $code = trim((string) ($state['qr_code'] ?? ''));
        if ($code === '') {
            Notification::make()->warning()->title('Enter a QR code first.')->send();

            return;
        }

        $branchId = (int) ($state['branch_id'] ?? 0) ?: $this->defaultBranchId();

        try {
            $svc = app(RedemptionService::class);
            $lookup = $svc->lookup($code, $branchId);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Cannot redeem this QR')->body($e->getMessage())->send();

            return;
        }

        $member = $lookup['member'];
        $plan = $lookup['plan'];

        $this->ctx = [
            'mode' => $lookup['mode'],
            'worth' => (float) $lookup['worth'],
            'branch_locked' => (bool) $lookup['branch_locked'],
            'dealer_eligible' => (bool) $lookup['dealer_eligible'],
            'member' => $member ? trim(($member->member_code ?? '') . ' — ' . ($member->name ?? '')) : '—',
            'plan' => $plan ? (Translatable::pick($plan->name) ?: $plan->code) : '—',
            'cart' => $lookup['cart'],
        ];

        // Mode A is billing-branch-locked — pin the branch to the bond's branch.
        if ($lookup['mode'] === 'A' && $lookup['bond']) {
            $this->data['branch_id'] = (int) $lookup['bond']->branch_id;
        }
    }

    public function sendOtp(): void
    {
        $code = trim((string) ($this->data['qr_code'] ?? ''));
        $qr = RedeemableQr::where('qr_code', $code)->where('status', 'pending')->first();
        if (! $qr) {
            Notification::make()->danger()->title('QR is no longer pending.')->send();

            return;
        }

        $otp = app(RedemptionService::class)->generateOtp($qr);
        $member = $qr->member;
        $phone = (string) ($member?->phone ?? '');

        if ($phone === '') {
            Notification::make()->warning()->title('No phone on file')
                ->body('OTP generated: ' . $otp . ' (could not send — distributor has no phone).')->send();

            return;
        }

        $msg = "LORD ICL: Your stock-QR redemption OTP is {$otp}. Share it with the counter to complete the redemption.";
        $res = app(WhatsappSender::class)->sendText($phone, $msg);

        ($res['ok'] ?? false)
            ? Notification::make()->success()->title('OTP sent on WhatsApp')->body('Sent to the distributor\'s number.')->send()
            : Notification::make()->warning()->title('OTP generated, WhatsApp send failed')->body($res['message'] ?? 'Gateway error.')->send();
    }

    public function redeem(): void
    {
        $d = $this->form->getState();

        try {
            $invoice = app(RedemptionService::class)->redeem([
                'qr_code' => trim((string) ($d['qr_code'] ?? '')),
                'branch_id' => (int) ($d['branch_id'] ?? 0) ?: $this->defaultBranchId(),
                'otp' => $d['otp'] ?? null,
                'lines' => $d['lines'] ?? [],
                'buyer_gst' => $d['buyer_gst'] ?? null,
                'create_dealer' => (bool) ($d['create_dealer'] ?? false),
                'dealer' => [
                    'branch_name' => $d['dealer_branch_name'] ?? null,
                    'gst_no' => $d['buyer_gst'] ?? null,
                ],
                'created_by' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Redemption failed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Redeemed · ' . $invoice->invoice_no)
            ->body('Tax invoice ₹' . number_format((float) $invoice->grand_total, 2) . ' raised.'
                . ($invoice->dealer_created ? ' Dealer account opened.' : ''))
            ->actions([
                \Filament\Notifications\Actions\Action::make('print')
                    ->label('Print tax invoice')
                    ->url(route('redemption.pdf', $invoice), shouldOpenInNewTab: true),
            ])
            ->persistent()
            ->send();

        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->ctx = null;
        $this->form->fill([
            'branch_id' => $this->defaultBranchId(),
            'lines' => [],
            'qr_code' => null,
            'otp' => null,
            'create_dealer' => false,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function defaultBranchId(): int
    {
        return (int) (auth()->user()?->branch_id ?: 1);
    }

    protected function isDistributor(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'isDistributor') && $u->isDistributor();
    }

    protected function catalogOptions(): array
    {
        $default = Translatable::defaultLocale();

        return CatalogProduct::query()->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (CatalogProduct $p) => [
                $p->id => trim($p->code . ' — ' . Translatable::pick($p->name, $default) . ' (' . $p->material . ')'),
            ])->all();
    }

    /** Live price of one Mode-B row (₹ incl. GST). */
    protected function priceRow($catalogProductId, float $unitWeight, float $quantity): float
    {
        if (! $catalogProductId || $quantity <= 0) {
            return 0.0;
        }
        $cp = CatalogProduct::find($catalogProductId);
        if (! $cp) {
            return 0.0;
        }

        return (float) app(RedemptionService::class)->priceLine($cp, $unitWeight, $quantity)['line_total'];
    }

    protected function renderCartTotal(array $lines): string
    {
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $this->priceRow($l['catalog_product_id'] ?? null, (float) ($l['unit_weight'] ?? 0), (float) ($l['quantity'] ?? 0));
        }
        $worth = (float) ($this->ctx['worth'] ?? 0);
        $ok = $total + 0.01 >= $worth;
        $colour = $ok ? '#16a34a' : '#dc2626';
        $note = $ok ? 'meets the QR worth' : 'below the QR worth — add more';

        return '<span style="font-size:1.25rem;font-weight:700;color:' . $colour . '">₹' . number_format($total, 2) . '</span>'
            . ' <span style="color:#6b7280">/ worth ₹' . number_format($worth, 2) . ' — ' . $note . '</span>';
    }

    protected function renderModeACart(): string
    {
        $rows = '';
        foreach ($this->ctx['cart'] ?? [] as $l) {
            $rows .= '<tr>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee">' . e($l['description'] ?? '') . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">' . number_format((float) ($l['quantity'] ?? 0), 3) . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">₹' . number_format((float) ($l['line_total'] ?? 0), 2) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            return '<em>No metal lines found on the original sale.</em>';
        }

        return '<table style="width:100%;border-collapse:collapse">'
            . '<thead><tr><th style="text-align:left;padding:4px 8px">Item</th><th style="text-align:right;padding:4px 8px">Qty</th><th style="text-align:right;padding:4px 8px">Amount</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }
}
