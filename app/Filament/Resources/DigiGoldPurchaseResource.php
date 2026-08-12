<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\DigiGoldPurchaseResource\Pages;
use App\Models\DigiGoldPurchase;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Digi Market buys from the app — read-only audit for HQ. Rows are created by
 * the app (wallet funding settles instantly; online settles via Razorpay
 * verify/webhook); grams live in digi_gold_txns and the wallet gram columns.
 */
class DigiGoldPurchaseResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = DigiGoldPurchase::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-currency-rupee';

    protected static ?string $modelLabel = 'Digi Market Buy';

    protected static ?string $pluralModelLabel = 'Digi Market Buys';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Started')->since()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (DigiGoldPurchase $p) => $p->member?->member_code),
                Tables\Columns\TextColumn::make('metal')->badge()
                    ->formatStateUsing(fn (?string $state) => ucfirst($state ?? 'gold'))
                    ->color(fn (?string $state) => $state === 'silver' ? 'gray' : 'warning'),
                Tables\Columns\TextColumn::make('funding')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'wallet' ? 'Cash wallet' : 'Online')
                    ->color(fn (?string $state) => $state === 'wallet' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('grams')->label('Weight')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 4) . ' g'),
                Tables\Columns\TextColumn::make('rate')->label('Rate/g')->baseMoney(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'failed' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('razorpay_payment_id')->label('Payment id')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['created' => 'Created', 'paid' => 'Paid', 'failed' => 'Failed']),
                Tables\Filters\SelectFilter::make('metal')
                    ->options(['gold' => 'Gold', 'silver' => 'Silver']),
                Tables\Filters\SelectFilter::make('funding')
                    ->options(['wallet' => 'Cash wallet', 'online' => 'Online']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDigiGoldPurchases::route('/'),
        ];
    }
}
