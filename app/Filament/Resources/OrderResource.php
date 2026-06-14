<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Order::class;

    protected static ?string $navigationGroup = 'Orders';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    public static function canCreate(): bool
    {
        return false; // orders come from the storefront checkout
    }

    public static function form(Form $form): Form
    {
        // only the operational fields are editable from admin
        return $form->schema([
            Forms\Components\Select::make('status')
                ->options(['pending' => 'Pending', 'paid' => 'Paid', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'])
                ->required(),
            Forms\Components\Select::make('payment_status')
                ->options(['unpaid' => 'Unpaid', 'paid' => 'Paid', 'refunded' => 'Refunded'])
                ->required(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('order_no')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('customer_name')->searchable(),
            Tables\Columns\TextColumn::make('total')->baseMoney()->sortable(),
            Tables\Columns\TextColumn::make('currency_code')->badge(),
            Tables\Columns\TextColumn::make('status')->badge()
                ->color(fn ($state) => match ($state) { 'paid', 'delivered' => 'success', 'cancelled' => 'danger', 'shipped' => 'info', default => 'warning' }),
            Tables\Columns\TextColumn::make('payment_status')->badge(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')
                ->options(['pending' => 'Pending', 'paid' => 'Paid', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled']),
        ])->actions([Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Order')->columns(3)->schema([
                Infolists\Components\TextEntry::make('order_no'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('payment_status')->badge(),
                Infolists\Components\TextEntry::make('customer_name'),
                Infolists\Components\TextEntry::make('email'),
                Infolists\Components\TextEntry::make('phone'),
                Infolists\Components\TextEntry::make('address')->columnSpanFull(),
                Infolists\Components\TextEntry::make('total')->money(fn ($record) => $record->currency_code),
            ]),
            Infolists\Components\RepeatableEntry::make('items')->columns(3)->schema([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('qty'),
                Infolists\Components\TextEntry::make('line_total')->baseMoney(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
