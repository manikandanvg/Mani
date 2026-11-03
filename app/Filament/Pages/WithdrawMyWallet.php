<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * L-BOX — Withdraw My Wallet (board spec 2026-08-09). Dealer-only screen: pick a
 * wallet — own commission wallet OR the branch Digi cash wallet — enter an amount,
 * and the request lands in L-BOX → Wallet Withdrawals. Board 2026-11-03: like every
 * row at the branch it is funded by Head Office first (Request payment from HQ →
 * Admin paid → Disburse). Money logic lives in WalletWithdrawalService (requestFromPanel).
 * "My recent requests" is a real table (search / status / date range / sort, user 2026-11-03).
 */
class WithdrawMyWallet extends Page implements HasForms, HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Withdraw My Wallet';

    protected static ?string $title = 'Withdraw My Wallet';

    protected static string $view = 'filament.pages.withdraw-my-wallet';

    public ?array $data = [];

    /** @var array<int, array{heading?:string, kv?:array, columns?:array, rows?:array}> */
    public array $sections = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && method_exists($u, 'isDistributor') && $u->isDistributor() && $u->branch_id);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill(['wallet' => 'member_cash']);
        $this->refreshSections();
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function balances(): array
    {
        $u = auth()->user();

        return [
            'member_cash' => (float) ($u?->memberAccount?->wallet?->cash_balance ?? 0),
            'branch_digi' => (float) (Branch::find($u?->branch_id)?->digi_cash_balance ?? 0),
        ];
    }

    public function form(Form $form): Form
    {
        $b = $this->balances();

        return $form->schema([
            Forms\Components\Section::make(__('New withdrawal request'))->columns(2)->schema([
                Forms\Components\Radio::make('wallet')
                    ->label(__('Withdraw from'))
                    ->options([
                        'member_cash' => __('My commission wallet — balance ₹:b', ['b' => \App\Support\Money::group($b['member_cash'])]),
                        'branch_digi' => __('Branch Digi cash wallet — balance ₹:b', ['b' => \App\Support\Money::group($b['branch_digi'])]),
                    ])
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('amount')
                    ->numeric()->minValue(0.01)->required()->prefix('₹'),
                Forms\Components\TextInput::make('note')->maxLength(150),
            ]),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $d = $this->form->getState();

        try {
            $w = app(WalletWithdrawalService::class)->requestFromPanel(
                auth()->user(),
                (string) $d['wallet'],
                (float) $d['amount'],
                $d['note'] ?? null,
            );
        } catch (\Throwable $e) {
            Notification::make()->danger()->title(__('Request failed'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('Withdrawal request #:id submitted', ['id' => $w->id]))
            ->body(__('It is pending under L-BOX → Wallet Withdrawals; include it in "Request payment from HQ".'))
            ->send();

        $this->form->fill(['wallet' => $d['wallet']]);
        $this->refreshSections();
        $this->resetTable();
    }

    /** Balances block above the table. */
    protected function refreshSections(): void
    {
        $b = $this->balances();

        $this->sections = [
            ['heading' => __('Balances'), 'kv' => [
                __('My commission wallet') => \App\Support\Money::group($b['member_cash']),
                __('Branch Digi cash wallet') => \App\Support\Money::group($b['branch_digi']),
            ]],
        ];
    }

    /** The dealer's own commission-wallet rows + every branch-Digi row of their branch. */
    public function table(Table $table): Table
    {
        $u = auth()->user();
        $memberId = $u?->memberAccount?->id;

        return $table
            ->heading(__('My recent requests'))
            ->query(WalletWithdrawal::query()
                ->where('branch_id', (int) $u?->branch_id)
                ->where(fn (Builder $q) => $q->where('wallet', 'branch_digi')
                    ->when($memberId, fn ($q, $m) => $q->orWhere('member_id', $m))))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('Date'))->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('wallet')->label(__('Wallet'))->badge()->sortable()
                    ->formatStateUsing(fn (?string $state) => $state === 'branch_digi' ? __('Branch Digi cash') : __('Commission wallet'))
                    ->color(fn (?string $state) => $state === 'branch_digi' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('amount')->label(__('Amount'))->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->sortable()
                    ->formatStateUsing(fn ($state) => __(WalletWithdrawal::STATUSES[$state] ?? ucfirst((string) $state)))
                    ->color(fn ($state) => match ($state) {
                        'disbursed', 'admin_paid' => 'success', 'cancelled' => 'danger', 'requested' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('payout_request_id')->label(__('Payout request'))->sortable()
                    ->formatStateUsing(fn ($state) => $state ? '#' . $state : '—'),
                Tables\Columns\TextColumn::make('disbursed_at')->label(__('Disbursed at'))->dateTime('d M Y H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label(__('Note'))->limit(40)->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(WalletWithdrawal::STATUSES)->multiple(),
                Tables\Filters\SelectFilter::make('wallet')->options(['member_cash' => __('Commission wallet'), 'branch_digi' => __('Branch Digi cash')]),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->native(false),
                        Forms\Components\DatePicker::make('to')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? __('From :d', ['d' => $data['from']]) : null,
                        ($data['to'] ?? null) ? __('To :d', ['d' => $data['to']]) : null,
                    ])),
            ])
            ->filtersFormColumns(3);
    }
}
