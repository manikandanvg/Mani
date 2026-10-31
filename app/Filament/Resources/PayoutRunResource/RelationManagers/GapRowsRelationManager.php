<?php

namespace App\Filament\Resources\PayoutRunResource\RelationManagers;

use App\Models\CommissionLedger;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The run's GAP rows, one per level per bond (legacy tbl_memberearning_level). */
class GapRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'gapRows';

    protected static ?string $title = 'GAP rows (per level per bond)';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('member.member_code')->label('Earner')->searchable()
                    ->description(fn (CommissionLedger $r) => $r->member?->name),
                Tables\Columns\TextColumn::make('level')->label('Level')->numeric(),
                Tables\Columns\TextColumn::make('fromMember.member_code')->label('From')->searchable()
                    ->description(fn (CommissionLedger $r) => $r->fromMember?->name),
                Tables\Columns\TextColumn::make('bond_id')->label('Contract #')->numeric(),
                Tables\Columns\TextColumn::make('invoice_no')->label('Bill')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->formatStateUsing(fn ($state) => Money::inr((float) $state))
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->formatStateUsing(fn ($state) => Money::inr((float) $state))),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) { 'paid' => 'success', default => 'warning' }),
                Tables\Columns\TextColumn::make('net_amount')->label('Net paid')->formatStateUsing(fn ($state, CommissionLedger $r) => $r->status === 'paid' ? Money::inr((float) $state) : '—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid']),
                Tables\Filters\SelectFilter::make('level')->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
            ])
            ->paginated([25, 50, 100, 250]);
    }
}
