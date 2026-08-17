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

    protected static ?int $navigationSort = 3;

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
                // Board 2026-08-12 (web item 4): make the three origins tell apart at a
                // glance — app QR scan at the box vs the two dealer-panel request pots.
                Tables\Columns\TextColumn::make('source')->label('Source')->badge()
                    ->getStateUsing(fn (WalletWithdrawal $w) => $w->device_id !== null
                        ? 'lbox'
                        : ($w->wallet === 'branch_digi' ? 'panel_branch_digi' : 'panel_member_cash'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'lbox' => __('L-BOX QR'),
                        'panel_branch_digi' => __('Panel — Branch Digi'),
                        default => __('Panel — Cash wallet'),
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'lbox' => 'heroicon-m-qr-code',
                        default => 'heroicon-m-computer-desktop',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'lbox' => 'primary',            // brand maroon — scanned at the box
                        'panel_branch_digi' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('wallet')->label('Wallet')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'branch_digi' ? __('Branch Digi') : __('Commission'))
                    ->color(fn (?string $state) => $state === 'branch_digi' ? 'warning' : 'info'),
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
                Tables\Filters\SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'lbox' => 'L-BOX QR',
                        'panel_member_cash' => 'Panel — Cash wallet',
                        'panel_branch_digi' => 'Panel — Branch Digi',
                    ])
                    // device_id marks an app scan at the box; panel rows carry no device
                    // and split by which pot the money left (wallet column, 2026-08-09).
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'lbox' => $query->whereNotNull('device_id'),
                        'panel_member_cash' => $query->whereNull('device_id')->where('wallet', 'member_cash'),
                        'panel_branch_digi' => $query->whereNull('device_id')->where('wallet', 'branch_digi'),
                        default => $query,
                    }),
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
