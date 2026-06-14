<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\MouResource\Pages;
use App\Filament\Resources\MouResource\RelationManagers;
use App\Models\Mou;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MouResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Mou::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Master';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translatable::fieldset('title', 'Title'),
                Translatable::fieldset('terms', 'Terms', textarea: true),
                Forms\Components\TextInput::make('duration_months')
                    ->required()
                    ->numeric()
                    ->default(12),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Translatable::column('title', 'Title'),
                Tables\Columns\TextColumn::make('duration_months')
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
            'index' => Pages\ListMous::route('/'),
            'create' => Pages\CreateMou::route('/create'),
            'edit' => Pages\EditMou::route('/{record}/edit'),
        ];
    }
}
