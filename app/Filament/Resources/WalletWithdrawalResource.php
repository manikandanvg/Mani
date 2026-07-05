<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\WalletWithdrawalResource\Pages;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Closed-wallet withdrawals at the branch counter. The member scanned this branch's
 * L-BOX QR (the box announced it); the incharge hands over GOLD or CASH and marks
 * the row disbursed. Cancelling a pending row refunds the member's wallet.
 * Branch-scoped: an incharge sees only their own branch's queue.
 */
class WalletWithdrawalResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = WalletWithdrawal::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'Wallet Withdrawal';

    protected static ?string $pluralModelLabel = 'Wallet Withdrawals';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;   // rows are created by the member's QR scan in the app
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Requested')->since()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (WalletWithdrawal $w) => $w->member?->member_code),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch'),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'disbursed' => 'success', 'cancelled' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('disbursal_mode')->label('Given as')->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '—')
                    ->color(fn ($state) => $state === 'gold' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('disbursed_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('note')->limit(30)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'disbursed' => 'Disbursed', 'cancelled' => 'Cancelled'])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('disburse')
                    ->label('Disburse')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (WalletWithdrawal $w) => $w->status === 'pending')
                    ->form([
                        Forms\Components\Select::make('mode')
                            ->label('Handed over as')
                            ->options(['cash' => 'Cash', 'gold' => 'Gold'])
                            ->required(),
                        Forms\Components\TextInput::make('note'),
                    ])
                    ->modalDescription('Confirm only AFTER the gold/cash is physically handed to the distributor.')
                    ->action(function (WalletWithdrawal $record, array $data) {
                        app(WalletWithdrawalService::class)->disburse($record, $data['mode'], auth()->id(), $data['note'] ?? null);
                        Notification::make()->title('Withdrawal disbursed')->success()->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel & refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (WalletWithdrawal $w) => $w->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('The full amount goes back to the distributor\'s wallet.')
                    ->form([
                        Forms\Components\TextInput::make('note')->label('Reason'),
                    ])
                    ->action(function (WalletWithdrawal $record, array $data) {
                        app(WalletWithdrawalService::class)->cancel($record, auth()->id(), $data['note'] ?? null);
                        Notification::make()->title('Cancelled — wallet refunded')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletWithdrawals::route('/'),
        ];
    }
}
