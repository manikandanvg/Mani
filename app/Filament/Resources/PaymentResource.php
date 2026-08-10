<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Payment::class;

    protected static ?string $navigationGroup = 'Online Orders';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;   // payments are recorded by the gateway, never hand-entered
    }

    /**
     * Board spec 2026-08-09: "Order Management instead of Payments" — the nav slot is
     * taken by the fulfilment pipeline page; payment rows stay reachable from each
     * order's view screen and via direct URL for admins.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('order.order_no')->label('Order')->searchable()
                    ->url(fn (Payment $r) => $r->order_id ? OrderResource::getUrl('view', ['record' => $r->order_id]) : null),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'failed' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('method')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('razorpay_payment_id')->label('Payment ID')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('razorpay_order_id')->label('RZP Order')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('contact')->label('Phone')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['created' => 'Created', 'paid' => 'Paid', 'failed' => 'Failed']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
