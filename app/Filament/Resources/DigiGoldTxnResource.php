<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\DigiGoldTxnResource\Pages;
use App\Models\DigiGoldTxn;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Digi Market gram ledger — every settled BUY (cash → metal) and every SELL
 * (metal → cash wallet, minus the platform fee) in one read-only screen, clearly
 * badged apart (board 2026-08-12, web item 5). Buys land here on settlement
 * (source buy / buy_wallet); sells are the withdraw rows written by
 * DigiMarketService::withdrawToWallet(). Legacy sources (scan_pay, admin_adjust)
 * show under their own name. Payment-gateway attempts (created/failed Razorpay
 * rows) stay on the separate "Digi Market Buys" audit screen.
 */
class DigiGoldTxnResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = DigiGoldTxn::class;

    protected static ?string $navigationGroup = 'L-BOX';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $modelLabel = 'Digi Market Txn';

    protected static ?string $pluralModelLabel = 'Digi Market Buys & Sells';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;   // ledger rows come only from the app's buy/sell flows
    }

    /** BUY | SELL | anything legacy (scan_pay, admin_adjust…). */
    protected static function kind(DigiGoldTxn $txn): string
    {
        return match (true) {
            in_array($txn->source, ['buy', 'buy_wallet'], true) => 'buy',
            $txn->source === 'withdraw' => 'sell',
            default => 'other',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->since()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (DigiGoldTxn $t) => $t->member?->member_code),
                Tables\Columns\TextColumn::make('direction')->label('Type')->badge()
                    ->getStateUsing(fn (DigiGoldTxn $t) => static::kind($t))
                    ->formatStateUsing(fn (string $state, DigiGoldTxn $t) => match ($state) {
                        'buy' => __('BUY'),
                        'sell' => __('SELL / Withdraw'),
                        default => strtoupper(str_replace('_', ' ', (string) $t->source)),
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'buy' => 'heroicon-m-arrow-down-circle',
                        'sell' => 'heroicon-m-arrow-up-circle',
                        default => 'heroicon-m-adjustments-horizontal',
                    })
                    ->color(fn (string $state, DigiGoldTxn $t) => match ($state) {
                        'buy' => 'success',
                        'sell' => 'warning',
                        default => $t->type === 'credit' ? 'info' : 'gray',
                    }),
                Tables\Columns\TextColumn::make('metal')->badge()
                    ->formatStateUsing(fn (?string $state) => ucfirst($state ?? 'gold'))
                    ->color(fn (?string $state) => $state === 'silver' ? 'gray' : 'warning'),
                Tables\Columns\TextColumn::make('grams')->label('Weight')
                    ->formatStateUsing(fn ($state, DigiGoldTxn $t) => ($t->type === 'debit' ? '−' : '+')
                        . number_format((float) $state, 4) . ' g'),
                Tables\Columns\TextColumn::make('rate')->label('Rate/g')->baseMoney(),
                Tables\Columns\TextColumn::make('value')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('fee')->label('Platform fee')->baseMoney()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('balance_after')->label('Balance after')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 4) . ' g')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reference')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->label('Buy / Sell')
                    ->options(['buy' => 'Buy', 'sell' => 'Sell / Withdraw'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'buy' => $query->whereIn('source', ['buy', 'buy_wallet']),
                        'sell' => $query->where('source', 'withdraw'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('metal')
                    ->options(['gold' => 'Gold', 'silver' => 'Silver']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDigiGoldTxns::route('/'),
        ];
    }
}
