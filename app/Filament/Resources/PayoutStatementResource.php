<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PayoutStatementResource\Pages;
use App\Filament\Resources\PayoutStatementResource\RelationManagers;
use App\Models\PayoutStatement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PayoutStatementResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = PayoutStatement::class;

    protected static ?string $navigationGroup = 'Commissions & Payouts';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    // Old payout-statement system retired — the redesigned Commission Approval covers payouts
    // (incl. TDS). Hidden from nav; kept only so existing data/routes don't 404.
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
                Forms\Components\TextInput::make('period')
                    ->required()
                    ->maxLength(7),
                Forms\Components\TextInput::make('item_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('net_total')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('tds')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('service_charge')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('grand_total')
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
                Tables\Columns\TextColumn::make('period')
                    ->searchable(),
                Tables\Columns\TextColumn::make('item_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tds')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_charge')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('grand_total')
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
            'index' => Pages\ListPayoutStatements::route('/'),
            'edit' => Pages\EditPayoutStatement::route('/{record}/edit'),
        ];
    }
}
