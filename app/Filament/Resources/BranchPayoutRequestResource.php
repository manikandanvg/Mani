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
            ->searchPlaceholder(__('Search any field, all statuses'))
            ->defaultSort('id', 'desc')
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')->sortable()
                    ->searchable(query: fn ($query, string $search) => static::whereDateMatches($query, 'created_at', $search)
                        ->orWhereHas('requestedBy', fn ($u) => $u->where('name', 'like', "%{$search}%")))
                    ->description(fn (BranchPayoutRequest $r) => $r->requestedBy?->name),
                // User 2026-11-04: "branch name (userid)" — the dealer's distributor ID beside the branch name.
                Tables\Columns\TextColumn::make('branch.name')->label('From branch')->sortable()
                    ->formatStateUsing(fn ($state, BranchPayoutRequest $r) => ($uid = $r->dealerUserId()) ? $state . ' (' . $uid . ')' : $state)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('branch', fn ($b) => $b->where('name', 'like', "%{$search}%")
                        ->orWhereIn('level', static::labelKeysMatching(Branch::LEVEL_LABELS, $search))
                        ->orWhereHas('distributorUser', fn ($u) => $u->where('member_code', 'like', "%{$search}%"))))
                    ->description(fn (BranchPayoutRequest $r) => trim(($r->branch?->city ?? '') . ($r->branch?->level ? ' · ' . (Branch::LEVEL_LABELS[$r->branch->level] ?? $r->branch->level) : ''))),
                Tables\Columns\TextColumn::make('branch.city')->label('City')->sortable()->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch.phone')->label('Phone')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('bank')->label('Branch bank details')
                    ->getStateUsing(fn (BranchPayoutRequest $r) => $r->dealerBank()?->bank_acno)
                    ->copyable()->placeholder('— no bank on file —')
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('branch.distributorUser.memberAccount', fn ($m) => $m->where('bank_acno', 'like', "%{$search}%")
                        ->orWhere('bank_name', 'like', "%{$search}%")
                        ->orWhere('ifsc', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")))
                    ->description(fn (BranchPayoutRequest $r) => ($d = $r->dealerBank())
                        ? trim(($d->bank_name ?? '') . ($d->ifsc ? ' · IFSC ' . $d->ifsc : '') . ' · ' . $d->name)
                        : null),
                Tables\Columns\TextColumn::make('rows_count')->label('No. of requests')->numeric()->sortable()->searchable()->alignEnd(),
                // The screen groups the amount (₹1,20,000) while the column holds 120000.00.
                Tables\Columns\TextColumn::make('amount')->label('Net amount')->baseMoney()->sortable()
                    ->searchable(query: function ($query, string $search) {
                        $digits = str_replace([',', '₹'], '', $search);

                        return $digits === '' ? $query->whereRaw('1 = 0') : $query->where('amount', 'like', "%{$digits}%");
                    }),
                Tables\Columns\TextColumn::make('status')->badge()->sortable()
                    ->searchable(query: fn ($query, string $search) => $query->whereIn('status', static::labelKeysMatching(BranchPayoutRequest::STATUSES, $search)))
                    ->formatStateUsing(fn ($state) => __(BranchPayoutRequest::STATUSES[$state] ?? ucfirst((string) $state)))
                    ->color(fn ($state) => match ($state) { 'paid' => 'success', 'cancelled' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y H:i')->sortable()->placeholder('—')
                    ->searchable(query: fn ($query, string $search) => static::whereDateMatches($query, 'paid_at', $search)
                        ->orWhereHas('paidBy', fn ($u) => $u->where('name', 'like', "%{$search}%")))
                    ->description(fn (BranchPayoutRequest $r) => $r->paidBy?->name),
                Tables\Columns\TextColumn::make('payment_ref')->label('Reference')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->limit(30)->searchable()->toggleable(),
            ])
            ->filters([
                // The default "Requested" work queue must not hide paid/cancelled bundles from the
                // search box (a UTR only exists on a paid bundle). Picking any other status still narrows.
                Tables\Filters\SelectFilter::make('status')->options(BranchPayoutRequest::STATUSES)->multiple()->default(['requested'])
                    ->query(function (Builder $query, array $data, $livewire) {
                        $values = $data['values'] ?? [];
                        if ($values !== [] && ! static::searchOverridesStatusFilter($values, $livewire)) {
                            $query->whereIn('status', $values);
                        }
                    })
                    ->indicateUsing(function (array $state, $livewire) {
                        $values = $state['values'] ?? [];
                        if ($values === [] || static::searchOverridesStatusFilter($values, $livewire)) {
                            return [];
                        }

                        return [__('Status') . ': ' . collect(BranchPayoutRequest::STATUSES)->only($values)->map(fn ($label) => __($label))->join(', ', ' & ')];
                    }),
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

    /** True while a search is typed and the status filter is only on its default "Requested". */
    protected static function searchOverridesStatusFilter(array $values, $livewire): bool
    {
        return $values === ['requested'] && filled($livewire->getTableSearch());
    }

    /** Keys of a key => label list whose label (English or translated) contains the search word. */
    protected static function labelKeysMatching(array $labels, string $search): array
    {
        return array_keys(array_filter($labels, fn ($label) => mb_stripos($label, $search) !== false
            || mb_stripos(__($label), $search) !== false));
    }

    /**
     * Matches a search word against a timestamp column as the table shows it (05 Nov 2026 14:32):
     * a month name becomes its number, 05-11-2026 becomes 2026-11-05, anything else is a plain LIKE
     * on the stored value. Same SQL on MySQL and on the SQLite test database.
     */
    protected static function whereDateMatches($query, string $column, string $search)
    {
        $month = array_search(strtolower(substr($search, 0, 3)), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);

        if ($month !== false && ctype_alpha($search)) {
            $term = sprintf('-%02d-', $month + 1);
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $search, $m)) {
            $term = sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
        } else {
            $term = $search;
        }

        return $query->where($column, 'like', "%{$term}%");
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchPayoutRequests::route('/'),
        ];
    }
}
