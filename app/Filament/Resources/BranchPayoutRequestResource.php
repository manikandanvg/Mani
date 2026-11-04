<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BranchPayoutRequestResource\Pages;
use App\Models\Branch;
use App\Models\BranchPayoutRequest;
use App\Services\Lbox\WalletWithdrawalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Board 2026-11-03 step 3 — the bundles branches ask Head Office to fund. One row per
 * request: branch + city + phone, the dealer's bank details, number of withdrawals, net
 * amount, and Pay. Paying records the transfer reference and turns every withdrawal in the
 * bundle to "Admin paid" so the branch can disburse. HQ may cancel an unpaid bundle (rows
 * back to pending). A dealer sees their own branch's requests read-only.
 */
class BranchPayoutRequestResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = BranchPayoutRequest::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $modelLabel = 'Branch Payout Request';

    protected static ?string $pluralModelLabel = 'Branch Payout Requests';

    protected static ?string $slug = 'branch-payout-requests';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;   // created by the branch from Wallet Withdrawals → Request payment from HQ
    }

    /** User 2026-11-03: HQ sees "Branch Payout Requests", a dealer sees "HQ Payout Requests". */
    public static function getPluralModelLabel(): string
    {
        return WalletWithdrawalResource::isHq() ? __('Branch Payout Requests') : __('HQ Payout Requests');
    }

    public static function getModelLabel(): string
    {
        return WalletWithdrawalResource::isHq() ? __('Branch Payout Request') : __('HQ Payout Request');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'requested')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $hq = WalletWithdrawalResource::isHq();

        return $table
            ->description($hq
                ? __('Transfer the amount to the branch\'s bank, then press Pay and type the reference — the branch can then disburse each withdrawal.')
                : __('Your requests to Head Office. Once a bundle is paid, Disburse appears on its rows under Wallet Withdrawals.'))
            ->defaultSort('id', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')->sortable()
                    ->description(fn (BranchPayoutRequest $r) => $r->requestedBy?->name),
                // User 2026-11-04: "branch name (userid)" — the dealer's distributor ID beside the branch name.
                Tables\Columns\TextColumn::make('branch.name')->label('From branch')->sortable()->searchable()
                    ->formatStateUsing(fn ($state, BranchPayoutRequest $r) => ($uid = $r->dealerUserId()) ? $state . ' (' . $uid . ')' : $state)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('branch', fn ($b) => $b->where('name', 'like', "%{$search}%")
                        ->orWhereHas('distributorUser', fn ($u) => $u->where('member_code', 'like', "%{$search}%"))))
                    ->description(fn (BranchPayoutRequest $r) => trim(($r->branch?->city ?? '') . ($r->branch?->level ? ' · ' . (Branch::LEVEL_LABELS[$r->branch->level] ?? $r->branch->level) : ''))),
                Tables\Columns\TextColumn::make('branch.city')->label('City')->sortable()->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch.phone')->label('Phone')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('bank')->label('Branch bank details')
                    ->getStateUsing(fn (BranchPayoutRequest $r) => $r->dealerBank()?->bank_acno)
                    ->copyable()->placeholder('— no bank on file —')
                    ->description(fn (BranchPayoutRequest $r) => ($d = $r->dealerBank())
                        ? trim(($d->bank_name ?? '') . ($d->ifsc ? ' · IFSC ' . $d->ifsc : '') . ' · ' . $d->name)
                        : null),
                Tables\Columns\TextColumn::make('rows_count')->label('No. of requests')->numeric()->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('amount')->label('Net amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn ($state) => __(BranchPayoutRequest::STATUSES[$state] ?? ucfirst((string) $state)))
                    ->color(fn ($state) => match ($state) { 'paid' => 'success', 'cancelled' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y H:i')->sortable()->placeholder('—')
                    ->description(fn (BranchPayoutRequest $r) => $r->paidBy?->name),
                Tables\Columns\TextColumn::make('payment_ref')->label('Reference')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->limit(30)->searchable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(BranchPayoutRequest::STATUSES)->multiple()->default(['requested']),
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
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\Action::make('rows')
                    ->label('Withdrawals')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->url(fn (BranchPayoutRequest $r) => WalletWithdrawalResource::getUrl('index', [
                        'tableFilters' => ['status' => ['values' => []], 'payout' => ['id' => $r->id]],
                    ])),
                Tables\Actions\Action::make('pay')
                    ->label('Pay')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (BranchPayoutRequest $r) => $hq && $r->status === 'requested')
                    ->modalHeading(fn (BranchPayoutRequest $r) => __('Pay payout request #:id', ['id' => $r->id]))
                    ->modalDescription(fn (BranchPayoutRequest $r) => __('₹:a for :n withdrawal(s) to :b. Press Pay only AFTER the bank transfer is done — the branch then sees "Admin paid" and can disburse.', [
                        'a' => \App\Support\Money::group((float) $r->amount), 'n' => $r->rows_count, 'b' => $r->branch?->name,
                    ]))
                    ->form([
                        Forms\Components\TextInput::make('payment_ref')->label('Transfer reference / UTR')->maxLength(80),
                        Forms\Components\Textarea::make('note')->label('Note')->rows(2)->maxLength(255),
                    ])
                    ->action(function (BranchPayoutRequest $record, array $data) {
                        try {
                            app(WalletWithdrawalService::class)->payRequest($record, auth()->id(), $data['payment_ref'] ?? null, $data['note'] ?? null);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('Not paid'))->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('Paid — the branch can now disburse'))->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel request')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (BranchPayoutRequest $r) => $hq && $r->status === 'requested')
                    ->requiresConfirmation()
                    ->modalDescription('The withdrawals go back to pending at the branch. No money moves.')
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->maxLength(255)])
                    ->action(function (BranchPayoutRequest $record, array $data) {
                        app(WalletWithdrawalService::class)->cancelRequest($record, auth()->id(), $data['note'] ?? null);
                        Notification::make()->success()->title(__('Request cancelled — rows are pending again'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchPayoutRequests::route('/'),
        ];
    }
}
