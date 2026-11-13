<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Resources\MemberContractResource\Pages;
use App\Models\MemberContract;
use App\Support\Translatable;
use Filament\Resources\Resource;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only register of member CONTRACTS (created at billing for is_contract plans).
 * Exists chiefly so the settlement engine's outcomes are visible and actionable:
 * 'matured' contracts await the manual withdraw/renewal decision (dealerships check
 * opening stock by hand); 'closed' ones were settled automatically.
 */
class MemberContractResource extends BaseResource
{
    protected static ?string $model = MemberContract::class;

    protected static ?string $navigationGroup = 'Sales & Contracts';

    protected static ?string $navigationLabel = 'Settlements';

    protected static ?string $modelLabel = 'Settlement';

    protected static ?string $pluralModelLabel = 'Settlements';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Matured contracts need a human decision — surface the count on the nav item. */
    public static function getNavigationBadge(): ?string
    {
        $n = MemberContract::where('status', 'matured')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['member', 'plan', 'branch', 'settlements', 'rdSettlement']);

        // Branch scope: a distributor login sees only its own branch's contracts.
        $user = auth()->user();
        if ($user && method_exists($user, 'isDistributor') && $user->isDistributor() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('contract_no')->label('Contract')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (MemberContract $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('plan.code')->label('Scheme')
                    ->description(fn (MemberContract $r) => Translatable::pick($r->plan?->name))
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('start_date')->label('Start')->date()->sortable(),
                Tables\Columns\TextColumn::make('end_date')->label('End')->date()->sortable(),
                Tables\Columns\TextColumn::make('settled_on')->label('Settled')->date()->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success',
                        'matured' => 'warning',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
                // Board phase 2 (2026-08-28): the admin-entered settlement credited to the wallet.
                Tables\Columns\TextColumn::make('settlement_amount')
                    ->label('Settlement')
                    ->state(fn (MemberContract $r) => ($r->settlements->sum('amount') + (float) ($r->rdSettlement?->worth ?? 0)) ?: null)
                    ->description(fn (MemberContract $r) => $r->rdSettlement
                        ? ucfirst($r->rdSettlement->metal) . ' QR · ' . $r->rdSettlement->dues_paid . '/' . $r->rdSettlement->dues_total . ' dues'
                            . ($r->rdSettlement->coins_missing ? ' · ' . $r->rdSettlement->coins_missing . ' coin(s) not returned' : '')
                        : null)
                    ->formatStateUsing(fn ($state) => $state ? '₹' . \App\Support\Money::group((float) $state) : null)
                    ->placeholder(fn (MemberContract $r) => $r->isExpired() ? __('Expired — not settled') : '—')
                    ->color('success'),
            ])
            ->filters([
                // Board 2026-10-31 (7): Settlements opens on the CLOSED / expired contracts —
                // the ones that need (or had) a settlement; running contracts live under Sales & Contracts.
                Tables\Filters\SelectFilter::make('stage')
                    ->label('Show')
                    ->options([
                        'closed' => 'Closed / expired contracts',
                        'matured' => 'Matured (needs decision)',
                        'active' => 'Running contracts',
                        'all' => 'All contracts',
                    ])
                    ->default('closed')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'closed') {
                        'closed' => $query->where(fn ($w) => $w->whereIn('status', ['closed', 'matured'])
                            ->orWhereDate('end_date', '<', now()->toDateString())),
                        'matured' => $query->where('status', 'matured'),
                        'active' => $query->where('status', 'active')->whereDate('end_date', '>=', now()->toDateString()),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('expired')
                    ->label('Expired, awaiting settlement')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where(fn ($w) => $w->where('status', 'matured')
                            ->orWhere(fn ($x) => $x->where('status', '!=', 'closed')->whereDate('end_date', '<', now()->toDateString())))
                        ->whereDoesntHave('settlements')),
                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Scheme')
                    ->relationship('plan', 'code'),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('Contract PDF')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (MemberContract $record) => route('contract.pdf', $record->bond_id), shouldOpenInNewTab: true)
                    ->visible(fn (MemberContract $record) => $record->bond_id !== null),
                // Board phase 2 (2026-08-28): once a contract has EXPIRED, Head Office types the
                // settlement value; it goes straight to the distributor's cash wallet.
                Tables\Actions\Action::make('generate_settlement')
                    ->label('Generate settlement')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->modalHeading(fn (MemberContract $record) => 'Generate settlement — ' . $record->contract_no)
                    ->modalDescription(fn (MemberContract $record) => ($record->member?->name ?? 'Distributor')
                        . ' · ' . ($record->plan?->code ?? '') . ' · contract value ₹' . \App\Support\Money::group((float) $record->amount)
                        . ' · ended ' . ($record->end_date?->format('d M Y') ?? '—')
                        . '. The value entered below is credited to the distributor\'s cash wallet immediately.')
                    ->modalWidth('md')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Settlement value (₹)')
                            ->numeric()->minValue(0.01)->required()
                            ->default(fn (MemberContract $record) => (float) $record->amount ?: null)
                            ->prefix('₹'),
                        Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255),
                    ])
                    ->visible(fn (MemberContract $record) => ! auth()->user()?->isDistributor()
                        && ! \App\Services\Rd\GoldSavingSettlementService::isGoldSaving($record)   // Gold Saving settles by QR, below
                        && \App\Services\ContractSettlementService::canGenerate($record))
                    ->action(function (MemberContract $record, array $data) {
                        try {
                            $row = app(\App\Services\ContractSettlementService::class)
                                ->generate($record, (float) $data['amount'], $data['note'] ?? null, auth()->id());
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Could not generate settlement')->body($e->getMessage())->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Settlement credited')
                            ->body('₹' . \App\Support\Money::group((float) $row->amount) . ' added to '
                                . ($record->member?->name ?? 'the distributor') . "'s wallet.")
                            ->send();
                    }),
                // Lord's board 2026-11-13 — Gold Saving: after the scheme date, the saver hands the
                // coins back and Head Office settles with a gold (or silver) QR.
                Tables\Actions\Action::make('settle_gold_saving')
                    ->label('Settle Gold Saving')
                    ->icon('heroicon-o-scale')
                    ->color('success')
                    ->modalHeading(fn (MemberContract $record) => 'Settle Gold Saving — ' . $record->contract_no)
                    ->modalWidth('lg')
                    ->form(fn (MemberContract $record) => [
                        Forms\Components\Placeholder::make('summary')->label('')
                            ->content(fn (Forms\Get $get) => self::goldSavingSummary($record, $get('coins_returned'))),
                        Forms\Components\TextInput::make('coins_returned')
                            ->label('100 mg coins handed back at the counter')
                            ->numeric()->integer()->minValue(0)->live(debounce: 500)
                            ->default(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'])
                            ->maxValue(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'])
                            ->visible(fn () => app(\App\Services\Rd\GoldSavingSettlementService::class)->quote($record)['coins_redeemed'] > 0)
                            ->helperText('Every coin not handed back is deducted at today\'s 100 mg price. QRs never redeemed are cancelled automatically.'),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch settling (takes the coins into its stock)')
                            ->options(fn () => \App\Models\Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->default(fn () => auth()->user()?->branch_id ?: \App\Models\Branch::where('level', 'hq')->value('id'))
                            ->searchable()->required(),
                        Forms\Components\TextInput::make('note')->label('Note (optional)')->maxLength(255),
                    ])
                    ->visible(fn (MemberContract $record) => ! auth()->user()?->isDistributor()
                        && \App\Services\Rd\GoldSavingSettlementService::canSettle($record))
                    ->action(function (MemberContract $record, array $data) {
                        try {
                            $row = app(\App\Services\Rd\GoldSavingSettlementService::class)->settle(
                                $record, (int) $data['branch_id'],
                                isset($data['coins_returned']) && $data['coins_returned'] !== '' ? (int) $data['coins_returned'] : null,
                                $data['note'] ?? null, auth()->id());
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Could not settle')->body($e->getMessage())->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Gold Saving settled')
                            ->body(ucfirst($row->metal) . ' QR worth ₹' . \App\Support\Money::group((float) $row->worth)
                                . ' issued to ' . ($record->member?->name ?? 'the distributor') . '.')
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** The settlement worked out for the modal: dues, coins, deduction, and what is given. */
    protected static function goldSavingSummary(MemberContract $record, $coinsReturned): \Illuminate\Support\HtmlString
    {
        try {
            $q = app(\App\Services\Rd\GoldSavingSettlementService::class)
                ->quote($record, $coinsReturned === null || $coinsReturned === '' ? null : (int) $coinsReturned);
        } catch (\Throwable $e) {
            return new \Illuminate\Support\HtmlString('<span class="text-sm text-danger-600">' . e($e->getMessage()) . '</span>');
        }
        $m = fn (float $v) => '₹' . \App\Support\Money::group($v);
        $rows = [
            ['Dues paid', $q['dues_paid'] . ' of ' . $q['dues_total']],
            ['Paid in total', $m($q['paid_total'])],
            ['Bonus', $q['bonus'] > 0 ? $q['bonus_months'] . ' × first month ' . $m($q['first_due']) . ' = ' . $m($q['bonus']) : 'none (' . ($q['dues_paid'] < $q['dues_total'] ? 'not all dues paid' : 'scheme has no bonus') . ')'],
            ['100 mg coins given', $q['coins_given'] . ' (' . $q['coins_cancelled'] . ' never redeemed — QR will be cancelled; ' . $q['coins_redeemed'] . ' to hand back)'],
            ['Coins not returned', $q['coins_missing'] . ($q['coins_missing'] ? ' × ' . $m($q['coin_price']) . ' = − ' . $m($q['deduction']) : '')],
            ['Settlement', strtoupper($q['metal']) . ' QR worth ' . $m($q['worth'])],
        ];
        $html = '<div class="grid grid-cols-2 gap-2 text-sm">';
        foreach ($rows as [$k, $v]) {
            $html .= '<div class="text-gray-500">' . e($k) . '</div><div class="font-semibold">' . e($v) . '</div>';
        }

        return new \Illuminate\Support\HtmlString($html . '</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberContracts::route('/'),
        ];
    }
}
