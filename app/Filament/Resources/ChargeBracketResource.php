<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\ChargeBracketResource\Pages;
use App\Filament\Resources\ChargeBracketResource\RelationManagers;
use App\Models\ChargeBracket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChargeBracketResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = ChargeBracket::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('material')
                    ->options(['gold' => 'Gold', 'silver' => 'Silver'])
                    ->default('gold')
                    ->required()
                    ->helperText('Cash→gold (or silver) reversal charges, looked up by weight.'),
                Forms\Components\TextInput::make('wt_from')
                    ->label('Weight from (grams)')
                    ->required()->numeric()->minValue(0)->step('0.0001')->suffix('g'),
                Forms\Components\TextInput::make('wt_to')
                    ->label('Weight to (grams)')
                    ->required()->numeric()->step('0.0001')->suffix('g')
                    ->gte('wt_from'),
                Forms\Components\TextInput::make('making_pct')
                    ->label('Making charge')
                    ->required()->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
                Forms\Components\TextInput::make('wastage_pct')
                    ->label('Wastage charge')
                    ->required()->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('wt_from')
            ->columns([
                Tables\Columns\TextColumn::make('material')->badge()
                    ->color(fn ($state) => $state === 'gold' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('wt_from')->label('From')
                    ->numeric(4)->suffix(' g')->sortable(),
                Tables\Columns\TextColumn::make('wt_to')->label('To')
                    ->numeric(4)->suffix(' g')->sortable(),
                Tables\Columns\TextColumn::make('making_pct')->label('Making')
                    ->numeric()->suffix(' %')->sortable(),
                Tables\Columns\TextColumn::make('wastage_pct')->label('Wastage')
                    ->numeric()->suffix(' %')->sortable(),
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
            'index' => Pages\ListChargeBrackets::route('/'),
            'create' => Pages\CreateChargeBracket::route('/create'),
            'edit' => Pages\EditChargeBracket::route('/{record}/edit'),
        ];
    }
}
