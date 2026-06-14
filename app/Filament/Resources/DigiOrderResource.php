<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\DigiOrderResource\Pages;
use App\Filament\Resources\DigiOrderResource\RelationManagers;
use App\Models\DigiOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DigiOrderResource extends BaseResource
{
    use BranchScoped;
    protected static ?string $model = DigiOrder::class;

    protected static ?string $navigationGroup = 'Digi-Gold';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    // Legacy digi-gold — superseded by the new Redeemable Stock QR redemption. Hidden from nav.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('ref_no')
                    ->required()
                    ->maxLength(40),
                Forms\Components\TextInput::make('member_id')
                    ->numeric(),
                Forms\Components\TextInput::make('source')
                    ->required(),
                Forms\Components\TextInput::make('gold_wt')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('rate_on_date')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ref_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source'),
                Tables\Columns\TextColumn::make('gold_wt')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_on_date')
                    ->numeric()
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
            'index' => Pages\ListDigiOrders::route('/'),
            'create' => Pages\CreateDigiOrder::route('/create'),
            'edit' => Pages\EditDigiOrder::route('/{record}/edit'),
        ];
    }
}
