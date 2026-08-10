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

/**
 * L-BOX — Withdraw My Wallet (board spec 2026-08-09). Dealer-only screen: pick a
 * wallet — own commission wallet OR the branch Digi cash wallet — enter an amount,
 * and the request lands in L-BOX → Wallet Withdrawals for HQ to disburse or cancel.
 * Money logic lives in WalletWithdrawalService (requestFromPanel).
 */
class WithdrawMyWallet extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use InteractsWithForms;

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
                        'member_cash' => __('My commission wallet — balance ₹:b', ['b' => number_format($b['member_cash'], 2)]),
                        'branch_digi' => __('Branch Digi cash wallet — balance ₹:b', ['b' => number_format($b['branch_digi'], 2)]),
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
            ->body(__('Head Office will disburse or cancel it under L-BOX → Wallet Withdrawals.'))
            ->send();

        $this->form->fill(['wallet' => $d['wallet']]);
        $this->refreshSections();
    }

    /** Balances + the dealer's recent requests, rendered by the shared partial. */
    protected function refreshSections(): void
    {
        $u = auth()->user();
        $b = $this->balances();

        $recent = WalletWithdrawal::where('branch_id', $u->branch_id)
            ->when($u->memberAccount, fn ($q, $m) => $q
                ->where(fn ($w) => $w->where('member_id', $m->id)->orWhere('wallet', 'branch_digi')))
            ->latest()->limit(15)->get();

        $this->sections = [
            ['heading' => __('Balances'), 'kv' => [
                __('My commission wallet') => number_format($b['member_cash'], 2),
                __('Branch Digi cash wallet') => number_format($b['branch_digi'], 2),
            ]],
            ['heading' => __('My recent requests'),
                'columns' => [__('Date'), __('Wallet'), __('Amount'), __('Status'), __('Disbursed at'), __('Note')],
                'rows' => $recent->map(fn ($w) => [
                    $w->created_at?->format('d M Y H:i'),
                    $w->wallet === 'branch_digi' ? __('Branch Digi cash') : __('Commission wallet'),
                    number_format((float) $w->amount, 2),
                    ucfirst((string) $w->status),
                    $w->disbursed_at?->format('d M Y H:i'),
                    $w->note,
                ])->all()],
        ];
    }
}
