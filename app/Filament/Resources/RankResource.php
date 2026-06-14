<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\RankResource\Pages;
use App\Filament\Resources\RankResource\RelationManagers;
use App\Models\Rank;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RankResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Rank::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(40),
                Translatable::fieldset('name', 'Name'),
                Forms\Components\TextInput::make('depth')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('target_bv')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('reward_amount')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TagsInput::make('tier_template')
                    ->helperText('Top-N downline GBV thresholds for GAP qualification'),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('depth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_bv')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reward_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort')
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
            'index' => Pages\ListRanks::route('/'),
            'create' => Pages\CreateRank::route('/create'),
            'edit' => Pages\EditRank::route('/{record}/edit'),
        ];
    }
}
