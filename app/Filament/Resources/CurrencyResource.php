<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CurrencyResource\Pages;
use App\Filament\Resources\CurrencyResource\RelationManagers;
use App\Models\Currency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CurrencyResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Currency::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(3),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(80),
                Forms\Components\TextInput::make('symbol')
                    ->required()
                    ->maxLength(8),
                Forms\Components\TextInput::make('decimals')
                    ->required()
                    ->numeric()
                    ->default(2),
                Forms\Components\TextInput::make('rate_to_base')
                    ->required()
                    ->numeric()
                    ->gt(0)   // 0 would freeze fx=1.0 onto every document at branches using this currency
                    ->default(1.00000000),
                Forms\Components\Toggle::make('is_base')
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('symbol')
                    ->searchable(),
                Tables\Columns\TextColumn::make('decimals')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_to_base')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_base')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
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
            'index' => Pages\ListCurrencies::route('/'),
            'create' => Pages\CreateCurrency::route('/create'),
            'edit' => Pages\EditCurrency::route('/{record}/edit'),
        ];
    }
}
