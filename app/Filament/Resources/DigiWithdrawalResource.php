<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\DigiWithdrawalResource\Pages;
use App\Filament\Resources\DigiWithdrawalResource\RelationManagers;
use App\Models\DigiWithdrawal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DigiWithdrawalResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = DigiWithdrawal::class;

    protected static ?string $navigationGroup = 'Digi-Gold';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    // Legacy digi-gold — superseded by the new Redeemable Stock QR redemption. Hidden from nav.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('member_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('gold_wt')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('worth')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gold_wt')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('worth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status'),
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
            'index' => Pages\ListDigiWithdrawals::route('/'),
            'create' => Pages\CreateDigiWithdrawal::route('/create'),
            'edit' => Pages\EditDigiWithdrawal::route('/{record}/edit'),
        ];
    }
}
