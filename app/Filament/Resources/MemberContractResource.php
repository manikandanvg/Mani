<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Resources\MemberContractResource\Pages;
use App\Models\MemberContract;
use App\Support\Translatable;
use Filament\Resources\Resource;
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
        $query = parent::getEloquentQuery()->with(['member', 'plan', 'branch']);

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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'matured' => 'Matured (needs decision)', 'closed' => 'Closed']),
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
