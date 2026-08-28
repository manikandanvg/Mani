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

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?string $navigationLabel = 'Contracts';

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
        $query = parent::getEloquentQuery()->with(['member', 'plan', 'branch', 'settlements']);

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
                    ->state(fn (MemberContract $r) => $r->settlements->sum('amount') ?: null)
                    ->formatStateUsing(fn ($state) => $state ? '₹' . \App\Support\Money::group((float) $state) : null)
                    ->placeholder(fn (MemberContract $r) => $r->isExpired() ? __('Expired — not settled') : '—')
                    ->color('success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'matured' => 'Matured (needs decision)', 'closed' => 'Closed']),
                Tables\Filters\Filter::make('expired')
                    ->label('Expired, awaiting settlement')
                    ->toggle()
                    ->query(fn (Builder $q) => $q
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
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemberContracts::route('/'),
        ];
    }
}
