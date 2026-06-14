<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\EpinResource\Pages;
use App\Filament\Resources\EpinResource\RelationManagers;
use App\Models\Epin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpinResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Epin::class;

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    // Not shown in the sidebar (e-pins are managed within plans/sales, not as a top-level screen).
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('owner_member_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('worth')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\TextInput::make('used_by_member_id')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('used_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('owner_member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('worth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('used_by_member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('used_at')
                    ->dateTime()
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
            'index' => Pages\ListEpins::route('/'),
            'create' => Pages\CreateEpin::route('/create'),
            'edit' => Pages\EditEpin::route('/{record}/edit'),
        ];
    }
}
