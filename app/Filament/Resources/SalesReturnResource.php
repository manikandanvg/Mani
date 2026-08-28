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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Customer return')->columns(2)->schema([
                Forms\Components\Select::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => auth()->user()?->branch_id)
                    ->visible(fn () => static::isHq())
                    ->searchable()->required(),
                Forms\Components\Select::make('member_id')->label('Customer (distributor)')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::query()
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                            ->orWhere('member_code', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(50)->get()
                        ->mapWithKeys(fn ($m) => [$m->id => "{$m->name} ({$m->member_code})"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? "{$m->name} ({$m->member_code})" : null),
                Forms\Components\Select::make('catalog_product_id')->label('Coin / item returned')
                    ->options(fn () => CustomizeOrderPricing::coinProductOptions())
                    ->default(fn () => CustomizeOrderPricing::coinProduct()?->id)
                    ->searchable()->required()->live()->native(false),
                Forms\Components\TextInput::make('quantity')->label('No. of coins / pieces')
                    ->numeric()->integer()->minValue(1)->required()->live(onBlur: true),
                Forms\Components\DateTimePicker::make('collect_on')->label('Collect on (date & time)')
                    ->seconds(false)->native(false),
                Forms\Components\Placeholder::make('credit')->label('Credit value (live rate)')
                    ->content(function (Get $get) {
                        $q = app(SalesReturnService::class)->quote($get('catalog_product_id') ? (int) $get('catalog_product_id') : null, (float) $get('quantity'));

                        return $q['value'] > 0
                            ? new HtmlString('<b>₹' . \App\Support\Money::group($q['value']) . '</b> <span class="text-xs text-gray-500">('
                                . rtrim(rtrim(number_format($q['grams'], 4), '0'), '.') . ' g × ₹' . \App\Support\Money::group($q['rate']) . '/g)</span>')
                            : '—';
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
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Item')->placeholder('—'),
                Tables\Columns\TextColumn::make('quantity')->label('Coins')->numeric(decimalPlaces: 0)->alignCenter(),
                Tables\Columns\TextColumn::make('grams')->label('Grams')->numeric(decimalPlaces: 3),
                Tables\Columns\TextColumn::make('credit_value')->label('Credit value')->baseMoney()->sortable(),
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
                        . ' coin(s) (' . number_format((float) $r->grams, 3) . ' g) from the customer. They will be counted into your branch stock.')
                    // User 2026-08-29: the confirmation is an ADMIN act — distributors only see the state.
                    ->visible(fn (SalesReturn $r) => $r->status === SalesReturn::STATUS_PENDING && static::isHq())
                    ->action(function (SalesReturn $r) {
                        try {
                            app(SalesReturnService::class)->markCollected($r, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not mark collected')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title($r->return_no . ' collected')
                            ->body('Coins added to the branch stock; they move to the supplier when the order is approved.')->send();
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
                Infolists\Components\TextEntry::make('orderRequest.request_no')->label('Applied to order')->placeholder('—'),
                Infolists\Components\TextEntry::make('catalogProduct.code')->label('Item')->placeholder('—'),
                Infolists\Components\TextEntry::make('quantity')->label('Coins')->numeric(decimalPlaces: 0),
                Infolists\Components\TextEntry::make('grams')->label('Grams')->numeric(decimalPlaces: 3),
                Infolists\Components\TextEntry::make('rate')->label('Rate /g')->baseMoney(),
                Infolists\Components\TextEntry::make('credit_value')->label('Credit value')->baseMoney(),
                Infolists\Components\TextEntry::make('collect_on')->label('Collect on')->dateTime('d M Y H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('collected_at')->dateTime()->placeholder('—'),
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
