<?php

namespace App\Filament\Pages;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\RdEntry;
use App\Models\Stock;
use App\Services\RdCollectionService;
use App\Support\Translatable;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Trade — RD / Gold-Saving Collection Entry. Records one monthly installment against an
 * EXISTING RD bond (legacy admin/trade/rdsales): pick the saver, see their schedule,
 * deduct the branch's cash stock, save the renewal. Branch comes from the session.
 */
class RdCollection extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'RD Collection Entry';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'RD / Gold-Saving Collection';

    protected static string $view = 'filament.pages.rd-collection';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'branch_id' => auth()->user()?->branch_id ?? Branch::min('id'),
            'paid_on' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Saver & schedule')->columns(2)->schema([
                    Select::make('bond_id')
                        ->label('Gold-Saving / RD profile')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => $this->bondOptions($search))
                        ->options(fn () => $this->bondOptions())
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $bond = $state ? Bond::with('plan')->find($state) : null;
                            // Gold-QR RD plans (PLUS1/PLUS2): the renewal amount is fixed
                            // at the current 100 mg gold price — prefill it, non-editable.
                            $fixed = $bond && (float) ($bond->plan?->rd_qr_grams ?? 0) > 0
                                ? app(\App\Services\Qr\GoldQrPricing::class)->price($bond->plan)
                                : null;
                            $set('amount', $fixed ?? ($bond ? (float) $bond->value : null));
                        })
                        ->columnSpanFull(),
                    Placeholder::make('schedule')
                        ->label('')
                        ->content(fn (Get $get) => $this->scheduleSummary($get('bond_id')))
                        ->columnSpanFull(),
                ]),
                Section::make('Collection')->columns(2)->schema([
                    Select::make('cash_stock_id')
                        ->label('Cash stock (this branch)')
                        ->options(fn (Get $get) => $this->cashStockOptions($get('branch_id')))
                        ->helperText('The collected amount is deducted from this cash holding.')
                        ->searchable(),
                    TextInput::make('amount')
                        ->label('Payment received (₹)')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->disabled(fn (Get $get) => (float) (Bond::with('plan')->find($get('bond_id'))?->plan?->rd_qr_grams ?? 0) > 0)
                        ->dehydrated()
                        ->helperText(fn (Get $get) => (float) (Bond::with('plan')->find($get('bond_id'))?->plan?->rd_qr_grams ?? 0) > 0
                            ? 'Fixed: current 100 mg gold price (incl. making/wastage/GST) — not editable.'
                            : null),
                    Textarea::make('remarks')->label('Remarks')->rows(2)->columnSpanFull(),
                    Hidden::make('branch_id'),
                ]),
            ])
            ->statePath('data');
    }

    /** Active RD/Gold-Saving bonds, labelled member (code) — plan — ₹installment. */
    protected function bondOptions(?string $search = null): array
    {
        $locale = Translatable::defaultLocale();

        return Bond::query()
            ->with(['member', 'plan'])
            ->whereHas('plan', fn ($q) => $q->where('type', 'rd'))
            ->where('status', 'active')
            ->when($search, fn ($q) => $q->whereHas('member', fn ($m) => $m
                ->where('name', 'like', "%{$search}%")
                ->orWhere('member_code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Bond $b) => [
                $b->id => sprintf(
                    '%s (%s) — %s — ₹%s',
                    $b->member?->name ?? 'Distributor',
                    $b->member?->member_code ?? '—',
                    $b->plan ? Translatable::pick($b->plan->name, $locale) : 'RD',
                    number_format((float) $b->value, 2)
                ),
            ])
            ->all();
    }

    protected function cashStockOptions(?int $branchId): array
    {
        if (! $branchId) {
            return [];
        }

        return Stock::where('branch_id', $branchId)
            ->whereHas('catalogProduct', fn ($q) => $q->where('material', 'cash'))
            ->with('catalogProduct')
            ->get()
            ->mapWithKeys(fn (Stock $s) => [
                $s->id => ($s->catalogProduct?->code ?? 'CASH') . ' — balance ₹' . number_format((float) $s->quantity, 2),
            ])
            ->all();
    }

    protected function scheduleSummary($bondId): HtmlString
    {
        if (! $bondId || ! ($bond = Bond::with('plan')->find($bondId))) {
            return new HtmlString('<span class="text-sm text-gray-500">Select a saver to see their schedule.</span>');
        }
        $paid = RdEntry::where('bond_id', $bond->id)->count();
        $total = (int) ($bond->lvlcom_count ?: 0);
        $pending = $total > 0 ? max(0, $total - $paid) : null;
        $next = $paid + 1;

        $rows = [
            ['Installment value', '₹' . number_format((float) $bond->value, 2)],
            ['Collected so far', $paid . ($total ? " / {$total}" : '')],
            ['This collection is due #', (string) $next],
            ['Pending', $pending === null ? '—' : (string) $pending],
        ];
        $html = '<div class="grid grid-cols-2 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $html .= '<div class="text-gray-500">' . e($k) . '</div><div class="font-semibold">' . e($v) . '</div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $entry = app(RdCollectionService::class)->collect([
            'bond_id' => $data['bond_id'],
            'branch_id' => $data['branch_id'],
            'amount' => $data['amount'],
            'cash_stock_id' => $data['cash_stock_id'] ?? null,
            'created_by' => auth()->id(),
        ]);

        Notification::make()
            ->success()
            ->title('Collection saved')
            ->body('Due #' . $entry->due_count . ' for ₹' . number_format((float) $entry->value, 2) . ' recorded.')
            ->send();

        $this->form->fill([
            'branch_id' => $data['branch_id'],
            'paid_on' => now()->toDateString(),
        ]);
    }
}
