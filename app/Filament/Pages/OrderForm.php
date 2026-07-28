<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Services\BranchOrderService;
use App\Support\Translatable;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Trade — Order Form. A distributor builds a cart of catalog products (priced live) and
 * submits a stock order to Head Office (legacy admin/trade/purchaseorderform). HQ approves
 * it in "Order Requests", which adds the items to this branch's stock.
 */
class OrderForm extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'Order Form';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Order Form — request stock from your supplier';

    protected static string $view = 'filament.pages.order-form';

    /** Legacy ordering window: stock orders may be placed only between these hours, IST. */
    public const ORDER_OPEN_HOUR = 9;   // 9:00 AM

    public const ORDER_CLOSE_HOUR = 21; // 9:00 PM

    public const ORDER_TIMEZONE = 'Asia/Kolkata';

    public ?array $data = [];

    public static function orderingOpen(): bool
    {
        $hour = now()->timezone(self::ORDER_TIMEZONE)->hour;

        return $hour >= self::ORDER_OPEN_HOUR && $hour < self::ORDER_CLOSE_HOUR;
    }

    public function getSubheading(): ?string
    {
        return static::orderingOpen()
            ? 'Ordering window 9:00 AM – 9:00 PM · OPEN now.'
            : 'Ordering window 9:00 AM – 9:00 PM · CLOSED now — orders cannot be submitted.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'branch_id' => auth()->user()?->branch_id ?? Branch::min('id'),
            'payment_type' => 'cash',
            'lines' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Supplier')->schema([
                    Placeholder::make('supplier')->label('')
                        ->content(fn () => $this->supplierBanner()),
                ]),
                Section::make('Items')->schema([
                    Repeater::make('lines')
                        ->label('')
                        ->addActionLabel('Add item')
                        ->columns(12)
                        ->live()
                        ->schema([
                            Select::make('catalog_product_id')
                                ->label('Product')
                                ->options(fn () => $this->productOptions())
                                ->searchable()
                                ->required()
                                ->live()
                                ->columnSpan(6),
                            TextInput::make('weight')
                                ->label('Weight (g) / Cash ₹')
                                ->numeric()
                                ->minValue(0.0001)
                                ->maxValue(BranchOrderService::MAX_LINE_QTY)
                                ->required()
                                ->live(onBlur: true)
                                ->columnSpan(3),
                            Placeholder::make('line_total')
                                ->label('Line total')
                                ->content(fn (Get $get) => new HtmlString(
                                    '<span class="font-semibold">₹' .
                                    number_format($this->lineTotal($get('catalog_product_id'), (float) $get('weight')), 2) .
                                    '</span>'
                                ))
                                ->columnSpan(3),
                        ]),
                ]),
                Section::make('Payment & summary')->columns(2)->schema([
                    Select::make('payment_type')
                        ->options(['cash' => 'Cash', 'cheque' => 'Cheque', 'online' => 'Online', 'digi_cash' => 'Digi Cash (wallet)', 'others' => 'Others'])
                        ->default('cash')->required()
                        ->helperText(fn () => 'Digi cash available: ₹'
                            . number_format((float) (auth()->user()?->branch?->digi_cash_balance ?? 0), 2)),
                    Textarea::make('payment_remarks')->label('Payment remarks')->rows(2),
                    Placeholder::make('summary')
                        ->label('')
                        ->content(fn (Get $get) => $this->summary($get('lines') ?? []))
                        ->columnSpanFull(),
                    Hidden::make('branch_id'),
                ]),
            ])
            ->statePath('data');
    }

    /** Shows the branch this order will be placed with (its source), with a link to change it. */
    protected function supplierBanner(): HtmlString
    {
        $branch = auth()->user()?->branch;
        $source = $branch?->sourceBranch;
        $name = $source?->name ?? 'Head Office';

        $html = '<div class="text-sm">Your stock orders go to <span class="font-semibold">' . e($name) . '</span>.';
        if (auth()->user()?->isDistributor()) {
            $html .= ' <span class="text-gray-500">To change your supplier, raise a request under Settings → Order Source Request.</span>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    protected function productOptions(): array
    {
        $locale = Translatable::defaultLocale();

        return CatalogProduct::where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (CatalogProduct $p) => [
                $p->id => $p->code . ' — ' . Translatable::pick($p->name, $locale) . ' (' . strtoupper($p->material) . ')',
            ])
            ->all();
    }

    protected function lineTotal($cpId, float $weight): float
    {
        if (! $cpId || $weight <= 0) {
            return 0.0;
        }
        $cp = CatalogProduct::find($cpId);
        if (! $cp) {
            return 0.0;
        }

        return app(BranchOrderService::class)
            ->priceLine($cp, $weight, LiveRate::latestFor('IN'))['line_total'];
    }

    protected function summary(array $lines): HtmlString
    {
        $svc = app(BranchOrderService::class);
        $rate = LiveRate::latestFor('IN');
        $cross = 0.0;
        $gst = 0.0;
        $count = 0;
        foreach ($lines as $l) {
            if (empty($l['catalog_product_id']) || (float) ($l['weight'] ?? 0) <= 0) {
                continue;
            }
            $cp = CatalogProduct::find($l['catalog_product_id']);
            if (! $cp) {
                continue;
            }
            $p = $svc->priceLine($cp, (float) $l['weight'], $rate);
            $cross += $p['subtotal'];
            $gst += $p['gst'];
            $count++;
        }
        $grand = $cross + $gst;
        $rows = [
            ['Items', (string) $count],
            ['Cross total', '₹' . number_format($cross, 2)],
            ['GST', '₹' . number_format($gst, 2)],
            ['Grand total', '₹' . number_format($grand, 2)],
        ];

        // Approximate equivalent in the viewer's currency (the order itself is in INR).
        $fxCode = \App\Support\Money::current();
        if (strtoupper($fxCode) !== strtoupper(\App\Support\Money::base()?->code ?? 'INR') && $grand > 0) {
            $sym = \App\Support\Money::currency($fxCode)?->symbol ?? '';
            $rows[] = ['Approx. (' . strtoupper($fxCode) . ')', $sym . ' ' . number_format(\App\Support\Money::convert($grand, $fxCode), 2)];
        }

        // Show remaining order headroom for a distributor: max(member BV, invested).
        $user = auth()->user();
        if ($user && $user->isDistributor()) {
            $limit = $user->orderLimit();
            if ($limit > 0) {
                $pending = (float) \App\Models\BranchOrderRequest::where('branch_id', $user->branch_id)
                    ->where('status', 'pending')->sum('grand_total');
                $rows[] = ['Order limit', '₹' . number_format($limit, 2)];
                $rows[] = ['Available now', '₹' . number_format(max(0, $limit - $pending - $grand), 2)];
            }
        }
        $html = '<div class="grid grid-cols-4 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $html .= '<div><div class="text-gray-500">' . e($k) . '</div><div class="font-semibold text-base">' . e($v) . '</div></div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    public function save(): void
    {
        if (! static::orderingOpen()) {
            Notification::make()
                ->warning()
                ->title('Ordering window closed')
                ->body('Stock orders can be placed only between 9:00 AM and 9:00 PM.')
                ->send();

            return;
        }

        $data = $this->form->getState();

        $request = app(BranchOrderService::class)->submit([
            'branch_id' => $data['branch_id'],
            'requested_by' => auth()->id(),
            'payment_type' => $data['payment_type'] ?? 'cash',
            'payment_remarks' => $data['payment_remarks'] ?? null,
            'lines' => $data['lines'] ?? [],
        ]);

        Notification::make()
            ->success()
            ->title('Order submitted')
            ->body($request->request_no . ' sent to Head Office for approval (₹' . number_format((float) $request->grand_total, 2) . ').')
            ->send();

        $this->form->fill([
            'branch_id' => $data['branch_id'],
            'payment_type' => 'cash',
            'lines' => [],
        ]);
    }
}
