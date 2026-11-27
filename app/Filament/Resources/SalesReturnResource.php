<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\SalesReturnResource\Pages;
use App\Models\Branch;
use App\Models\Member;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use App\Support\CustomizeOrderPricing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Trade — Sales Returns (board 2026-08-26). Coins / metal a customer hands back to a
 * distributor, valued at the live rate. Raised automatically when a customer's RD
 * 100 mg coins are applied on the Customize Order Form, or by hand here. The
 * distributor collects the coins at the agreed date & time ("Mark collected" → into
 * branch stock); approving the linked order relays them on to the supplier and takes
 * them out of the branch's inventory.
 */
class SalesReturnResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = SalesReturn::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationLabel = 'Sales Returns';

    protected static ?int $navigationSort = 8;

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', SalesReturn::STATUS_PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    protected static function isHq(): bool
    {
        $u = auth()->user();

        return $u && ! $u->isDistributor();
    }

    /** May the current user act on this return? HQ always; a distributor on their own branch. */
    protected static function canAct(SalesReturn $r): bool
    {
        $u = auth()->user();

        return $u && (static::isHq() || (int) $u->branch_id === (int) $r->branch_id);
    }

    /** Bill number ↔ distributor ↔ branch, for the form's live hint and its validation. */
    protected static function billCheck(Get $get): array
    {
        $u = auth()->user();
        $branchId = $u?->isDistributor() ? $u->branch_id : ($get('branch_id') ?: $u?->branch_id);

        return app(SalesReturnService::class)->checkBill($get('bill_no'), $get('member_id') ? (int) $get('member_id') : null, $branchId ? (int) $branchId : null);
    }

    /** The billed gold / silver items of the bill typed on the form (empty until the bill checks out). */
    protected static function billItems(Get $get): array
    {
        $u = auth()->user();
        $branchId = $u?->isDistributor() ? $u->branch_id : ($get('branch_id') ?: $u?->branch_id);
        if (blank($get('bill_no')) || ! $get('member_id')) {
            return [];
        }

        return app(SalesReturnService::class)->billItems($get('bill_no'), (int) $get('member_id'), $branchId ? (int) $branchId : null);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Customer return')->columns(2)->schema([
                Forms\Components\Select::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::fullOptions(true))
                    ->default(fn () => auth()->user()?->branch_id)
                    ->visible(fn () => static::isHq())
                    ->searchable()->required()->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('bill_no', null);
                        $set('catalog_product_id', null);
                    }),
                Forms\Components\Select::make('member_id')->label('Customer (distributor)')
                    ->searchable()
                    // only distributors this branch has billed (board 2026-11-27)
                    ->getSearchResultsUsing(function (string $search, Get $get) {
                        $u = auth()->user();
                        $branchId = $u?->isDistributor() ? $u->branch_id : ($get('branch_id') ?: $u?->branch_id);

                        return app(SalesReturnService::class)->billedMembers($branchId ? (int) $branchId : null)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(50)->get()
                            ->mapWithKeys(fn ($m) => [$m->id => "{$m->name} ({$m->member_code})"])->all();
                    })
                    ->noSearchResultsMessage('No distributor with a bill at this branch matches.')
                    ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->name} ({$m->member_code})" : null)
                    ->required()->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('bill_no', null);
                        $set('catalog_product_id', null);
                    }),
                // Board 2026-11-27: the bill the distributor bought on — picked from that
                // distributor's own bills at this branch, so a wrong number cannot be typed.
                Forms\Components\Select::make('bill_no')->label('Branch bill number')
                    ->options(function (Get $get) {
                        $u = auth()->user();
                        $branchId = $u?->isDistributor() ? $u->branch_id : ($get('branch_id') ?: $u?->branch_id);

                        return app(SalesReturnService::class)->billOptions($get('member_id') ? (int) $get('member_id') : null, $branchId ? (int) $branchId : null);
                    })
                    ->placeholder(fn (Get $get) => $get('member_id') ? 'Pick the bill' : 'Pick the distributor first')
                    ->noSearchResultsMessage('This distributor has no gold / silver bill at this branch.')
                    ->searchable()->native(false)->required()->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('catalog_product_id', null))
                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $c = static::billCheck($get);
                        if (! $c['ok']) {
                            $fail($c['message']);
                        }
                    }),
                // only the gold / silver items that bill sold — never its cash stock
                Forms\Components\Select::make('catalog_product_id')->label('Coin / item returned')
                    ->options(fn (Get $get) => collect(static::billItems($get))->map(fn ($i) => $i['label'])->all())
                    ->placeholder(fn (Get $get) => static::billItems($get) === [] ? 'Pick the distributor and the bill first' : 'Pick a billed item')
                    ->required()->live()->native(false),
                Forms\Components\TextInput::make('quantity')->label('No. of coins / pieces')
                    ->numeric()->integer()->minValue(1)->required()->live(onBlur: true)
                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        $item = static::billItems($get)[(int) $get('catalog_product_id')] ?? null;
                        if ($item && (float) $value > $item['left'] + 0.0001) {
                            $fail('Only ' . rtrim(rtrim(number_format($item['left'], 4), '0'), '.') . ' piece(s) of this item are left to return on this bill.');
                        }
                    }),
                Forms\Components\DateTimePicker::make('collect_on')->label('Collect on (date & time)')
                    ->seconds(false)->native(false),
                Forms\Components\Placeholder::make('credit')->label('Return value (live rate)')
                    ->content(function (Get $get) {
                        if (! $get('catalog_product_id')) {
                            return '—';
                        }
                        $q = app(SalesReturnService::class)->quote((int) $get('catalog_product_id'), (float) $get('quantity'), true);
                        if ($q['value'] <= 0) {
                            return '—';
                        }
                        $money = fn (float $v) => '₹' . \App\Support\Money::group($v);
                        $grams = rtrim(rtrim(number_format($q['grams'], 4), '0'), '.');

                        return new HtmlString('Coin value ' . $money($q['value']) . ' <span class="text-xs text-gray-500">(' . $grams . ' g × ' . $money($q['rate']) . '/g)</span>'
                            . ($q['charge'] > 0 ? '<br>Convenience charge ' . $money($q['charge']) . ' <span class="text-xs text-gray-500">(' . $grams . ' g × ₹'
                                . \App\Support\Money::group(SalesReturnService::convenienceRate()) . '/g)</span>' : '')
                            . '<br><b>Total ' . $money($q['total']) . '</b>');
                    }),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('return_no')->label('Return #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Raised')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('member.name')->label('Customer')
                    ->formatStateUsing(fn ($state, SalesReturn $r) => $r->member ? "{$r->member->name} ({$r->member->member_code})" : '—')
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('member',
                        fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('bill_no')->label('Bill no')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Item')->placeholder('—'),
                Tables\Columns\TextColumn::make('quantity')->label('Coins')->numeric(decimalPlaces: 0)->alignCenter(),
                Tables\Columns\TextColumn::make('grams')->label('Grams')->numeric(decimalPlaces: 3),
                Tables\Columns\TextColumn::make('credit_value')->label('Coin value')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('convenience_charge')->label('Convenience charge')->baseMoney()->toggleable(),
                Tables\Columns\TextColumn::make('total_value')->label('Total')->baseMoney()
                    ->state(fn (SalesReturn $r) => $r->total_value ?? $r->credit_value)->toggleable(),
                Tables\Columns\TextColumn::make('collect_on')->label('Collect on')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        SalesReturn::STATUS_COLLECTED => 'info',
                        SalesReturn::STATUS_RELAYED => 'success',
                        SalesReturn::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('orderRequest.request_no')->label('Order #')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('collected_at')->dateTime()->label('Collected')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    SalesReturn::STATUS_PENDING => 'Pending collection',
                    SalesReturn::STATUS_COLLECTED => 'Collected',
                    SalesReturn::STATUS_RELAYED => 'Relayed to supplier',
                    SalesReturn::STATUS_CANCELLED => 'Cancelled',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('3xl'),
                Tables\Actions\Action::make('collect')
                    ->label('Mark collected')
                    ->icon('heroicon-o-hand-thumb-up')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (SalesReturn $r) => 'Confirm you have received ' . rtrim(rtrim(number_format((float) $r->quantity, 4), '0'), '.')
                        . ' coin(s) (' . number_format((float) $r->grams, 3) . ' g). '
                        . (SalesReturnService::settlesWithHq($r)
                            ? 'They go into Head Office stock and ₹' . \App\Support\Money::group((float) ($r->total_value ?? $r->credit_value))
                                . ' is credited to the Digi cash of ' . ($r->branch?->name ?? 'the branch') . '.'
                            : 'They will be counted into the branch stock.'))
                    // User 2026-08-29: the confirmation is an ADMIN act — distributors only see the state.
                    ->visible(fn (SalesReturn $r) => $r->status === SalesReturn::STATUS_PENDING && static::isHq())
                    ->action(function (SalesReturn $r) {
                        try {
                            app(SalesReturnService::class)->markCollected($r, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not mark collected')->body($e->getMessage())->send();

                            return;
                        }
                        $r->refresh();
                        Notification::make()->success()->title($r->return_no . ' collected')
                            ->body($r->settled_at
                                ? 'Coins added to Head Office stock; ₹' . \App\Support\Money::group((float) $r->settled_amount) . ' credited to the branch Digi cash.'
                                : 'Coins added to the branch stock; they move to the supplier when the order is approved.')->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                    // returns tied to an order are cancelled by rejecting that order
                    ->visible(fn (SalesReturn $r) => $r->status === SalesReturn::STATUS_PENDING && ! $r->orderRequest && static::canAct($r))
                    ->action(function (SalesReturn $r) {
                        app(SalesReturnService::class)->cancel($r);
                        Notification::make()->warning()->title($r->return_no . ' cancelled')->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Sales return')->columns(3)->schema([
                Infolists\Components\TextEntry::make('return_no')->label('Return #'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('created_at')->dateTime()->label('Raised'),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch'),
                Infolists\Components\TextEntry::make('member.name')->label('Customer')
                    ->formatStateUsing(fn ($state, SalesReturn $r) => $r->member ? "{$r->member->name} ({$r->member->member_code})" : '—'),
                Infolists\Components\TextEntry::make('bill_no')->label('Branch bill number')->copyable()->placeholder('— not given —')
                    ->helperText(fn (SalesReturn $r) => $r->bill_no
                        ? app(SalesReturnService::class)->checkBill($r->bill_no, $r->member_id, $r->branch_id)['message'] : null),
                Infolists\Components\TextEntry::make('orderRequest.request_no')->label('Applied to order')->placeholder('—'),
                Infolists\Components\TextEntry::make('catalogProduct.code')->label('Item')->placeholder('—'),
                Infolists\Components\TextEntry::make('quantity')->label('Coins')->numeric(decimalPlaces: 0),
                Infolists\Components\TextEntry::make('grams')->label('Grams')->numeric(decimalPlaces: 3),
                Infolists\Components\TextEntry::make('rate')->label('Rate /g')->baseMoney(),
                Infolists\Components\TextEntry::make('credit_value')->label('Coin value')->baseMoney(),
                Infolists\Components\TextEntry::make('convenience_charge')->label('Convenience charge')->baseMoney(),
                Infolists\Components\TextEntry::make('total_value')->label('Total')->baseMoney()
                    ->state(fn (SalesReturn $r) => $r->total_value ?? $r->credit_value),
                Infolists\Components\TextEntry::make('collect_on')->label('Collect on')->dateTime('d M Y H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('collected_at')->dateTime()->placeholder('—'),
                Infolists\Components\TextEntry::make('settled_amount')->label('Credited to branch Digi cash')->baseMoney()->placeholder('—'),
                Infolists\Components\TextEntry::make('relayed_at')->label('Relayed to supplier')->dateTime()->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesReturns::route('/'),
            'create' => Pages\CreateSalesReturn::route('/create'),
        ];
    }
}
