<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CommissionLedgerResource\Pages;
use App\Filament\Resources\CommissionLedgerResource\RelationManagers;
use App\Models\CommissionLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommissionLedgerResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = CommissionLedger::class;

    protected static ?string $navigationGroup = 'Commissions';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('type')
                    ->required(),
                Forms\Components\TextInput::make('member_id')
                    ->label('Distributor ID')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('from_member_id')
                    ->label('From Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('bond_id')
                    ->numeric(),
                Forms\Components\TextInput::make('invoice_no')
                    ->maxLength(40),
                Forms\Components\TextInput::make('level')
                    ->label('Placement Layer')
                    ->numeric(),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\TextInput::make('pay_via'),
                Forms\Components\DatePicker::make('earned_on')
                    ->required(),
                Forms\Components\DatePicker::make('paid_on'),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('member_id')
                    ->label('Distributor ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('from_member_id')
                    ->label('From Distributor ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bond_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('invoice_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('level')
                    ->label('Placement Layer')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('pay_via'),
                Tables\Columns\TextColumn::make('earned_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionLedgers::route('/'),
            'create' => Pages\CreateCommissionLedger::route('/create'),
            'edit' => Pages\EditCommissionLedger::route('/{record}/edit'),
        ];
    }
}
