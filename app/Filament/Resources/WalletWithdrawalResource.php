<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\WalletWithdrawalResource\Pages;
use App\Models\Branch;
use App\Models\WalletWithdrawal;
use App\Services\Lbox\WalletWithdrawalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Closed-wallet withdrawals at the branch counter — board 2026-11-03, a branch never
 * fronts the cash:
 *   1. the member scanned this branch's QR → row pending (wallet already debited);
 *   2. the branch ticks pending rows → "Request payment from HQ" → requested;
 *   3. HQ pays the bundle under Branch Payout Requests → admin_paid;
 *   4. the branch hands over the cash/gold → Disburse.
 * Only Head Office cancels (refund to the wallet); HQ may also disburse a pending row
 * at its own counter. Branch-scoped: a dealer sees only their own branch's queue.
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

    /** Head Office = any non-distributor login that reaches this page (admin / super_admin). */
    public static function isHq(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ! $u->isDistributor());
    }

    public static function getNavigationBadge(): ?string
    {
        // HQ counts what waits on it; a branch counts its own open rows
        $open = static::getEloquentQuery()->whereIn('status', static::isHq() ? ['requested'] : ['pending', 'admin_paid'])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        $hq = static::isHq();

        return $table
            ->description($hq
                ? __('Branches request payment for their pending rows; pay the bundle under Branch Payout Requests, then the branch disburses. Cancel refunds the wallet.')
                : __('Tick your pending rows and press "Request payment from HQ". Disburse appears once Head Office has paid the bundle (Admin paid).'))
            ->defaultSort('id', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')->sortable()
                    ->description(fn (WalletWithdrawal $w) => $w->created_at?->diffForHumans()),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member', fn ($m) => $m
                        ->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                    ->description(fn (WalletWithdrawal $w) => trim(($w->member?->member_code ?? '') . ($w->member?->phone ? ' · ' . $w->member->phone : ''))),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable()->searchable()
                    ->description(fn (WalletWithdrawal $w) => $w->branch?->city),
                // Board 2026-11-02: the distributor's bank account, for the transfer
                Tables\Columns\TextColumn::make('member.bank_acno')->label('Bank account')->searchable()->copyable()->placeholder('— no bank on file —')
                    ->description(fn (WalletWithdrawal $w) => trim(($w->member?->bank_name ?? '') . ($w->member?->ifsc ? ' · IFSC ' . $w->member->ifsc : ''))),
                Tables\Columns\TextColumn::make('member.ifsc')->label('IFSC')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('member.bank_name')->label('Bank')->searchable()->toggleable(isToggledHiddenByDefault: true),
                // Board 2026-08-12 (web item 4): make the three origins tell apart at a
                // glance — app QR scan at the box vs the two dealer-panel request pots.
                Tables\Columns\TextColumn::make('source')->label('Source')->badge()
                    ->getStateUsing(fn (WalletWithdrawal $w) => $w->source())
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
                Tables\Columns\TextColumn::make('wallet')->label('Wallet')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state) => $state === 'branch_digi' ? __('Branch Digi') : __('Commission'))
                    ->color(fn (?string $state) => $state === 'branch_digi' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn ($state) => __(WalletWithdrawal::STATUSES[$state] ?? ucfirst((string) $state)))
                    ->icon(fn ($state) => $state === 'admin_paid' ? 'heroicon-m-check-circle' : null)
                    ->color(fn ($state) => match ($state) {
                        'disbursed' => 'success', 'cancelled' => 'danger', 'admin_paid' => 'success', 'requested' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('payout_request_id')->label('Payout request')->sortable()->toggleable()
                    ->formatStateUsing(fn ($state) => $state ? '#' . $state : '—')
                    ->url(fn (WalletWithdrawal $w) => $w->payout_request_id
                        ? BranchPayoutRequestResource::getUrl('index', ['tableFilters' => ['status' => ['values' => []]], 'tableSearch' => (string) $w->payout_request_id])
                        : null),
                Tables\Columns\TextColumn::make('disbursal_mode')->label('Given as')->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '—')
                    ->color(fn ($state) => $state === 'gold' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('disbursed_at')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('note')->limit(30)->searchable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(WalletWithdrawal::STATUSES)
                    ->multiple()
                    ->default(WalletWithdrawal::OPEN),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible($hq),
                Tables\Filters\Filter::make('requested')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Requested from')->native(false),
                        Forms\Components\DatePicker::make('to')->label('Requested to')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? __('From :d', ['d' => $data['from']]) : null,
                        ($data['to'] ?? null) ? __('To :d', ['d' => $data['to']]) : null,
                    ])),
                // reached from Branch Payout Requests → "Withdrawals" (tableFilters[payout][id] in the URL)
                Tables\Filters\Filter::make('payout')
                    ->form([Forms\Components\TextInput::make('id')->label('Payout request #')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['id'] ?? null, fn ($q, $id) => $q->where('payout_request_id', (int) $id)))
                    ->indicateUsing(fn (array $data) => ($data['id'] ?? null) ? __('Payout request #:id', ['id' => $data['id']]) : null),
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
            ->filtersFormColumns(4)
            ->bulkActions([
                // Step 2 — the branch asks HQ to fund its pending rows (one bundle per press).
                Tables\Actions\BulkAction::make('request_payout')
                    ->label('Request payment from HQ')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(! $hq)
                    ->requiresConfirmation()
                    ->modalHeading('Request payment from Head Office')
                    ->modalDescription(fn (Collection $records) => __(':n pending withdrawal(s), ₹:a in all. HQ transfers the amount to you, then Disburse appears on each row.', [
                        'n' => $records->where('status', 'pending')->count(),
                        'a' => \App\Support\Money::group((float) $records->where('status', 'pending')->sum('amount')),
                    ]))
                    ->form([Forms\Components\TextInput::make('note')->label('Note to HQ')->maxLength(150)])
                    ->action(function (Collection $records, array $data) {
                        $ids = $records->where('status', 'pending')->pluck('id')->all();
                        try {
                            $req = app(WalletWithdrawalService::class)->requestPayout(auth()->user(), $ids, $data['note'] ?? null);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('Request failed'))->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()
                            ->title(__('Payout request #:id sent to Head Office', ['id' => $req->id]))
                            ->body(__(':n withdrawal(s), ₹:a. You will be told when it is paid.', ['n' => $req->rows_count, 'a' => \App\Support\Money::group((float) $req->amount)]))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->checkIfRecordIsSelectableUsing(fn (WalletWithdrawal $w) => $w->status === 'pending')
            ->actions([
                Tables\Actions\Action::make('disburse')
                    ->label('Disburse')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    // Step 4 — the branch only after HQ paid; HQ may serve its own counter directly
                    ->visible(fn (WalletWithdrawal $w) => $w->status === 'admin_paid' || ($hq && $w->status === 'pending'))
                    ->form([
                        Forms\Components\Select::make('mode')
                            ->label('Handed over as')
                            ->options(['cash' => 'Cash', 'gold' => 'Gold'])
                            ->required(),
                        Forms\Components\TextInput::make('note'),
                    ])
                    ->modalDescription(fn (WalletWithdrawal $w) => 'Confirm only AFTER the gold/cash is physically handed to the distributor.'
                        . ($w->payout_request_id ? ' Funded by HQ under payout request #' . $w->payout_request_id . '.' : '')
                        . ($w->member?->bank_acno ? ' Bank on file: ' . $w->member->bank_name . ' · A/c ' . $w->member->bank_acno . ' · IFSC ' . $w->member->ifsc : ' No bank account on file for this distributor.'))
                    ->action(function (WalletWithdrawal $record, array $data) use ($hq) {
                        try {
                            app(WalletWithdrawalService::class)->disburse($record, $data['mode'], auth()->id(), $data['note'] ?? null, byHq: $hq);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('Not disbursed'))->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->title('Withdrawal disbursed')->success()->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel & refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    // Head Office only (board 2026-11-03); any open row, even one HQ already paid
                    ->visible(fn (WalletWithdrawal $w) => $hq && in_array($w->status, WalletWithdrawal::OPEN, true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (WalletWithdrawal $w) => 'The full amount goes back to the distributor\'s wallet.'
                        . ($w->status === 'admin_paid' ? ' HQ has already paid this row to the branch — recover it from the branch separately.' : ''))
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
