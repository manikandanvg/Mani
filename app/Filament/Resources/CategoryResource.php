<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CategoryResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Category::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translatable::fieldset('name', 'Name'),
                Forms\Components\Select::make('domain')
                    ->options(['ecommerce' => 'E-commerce (storefront)', 'trade' => 'Trade (stock/sales)'])
                    ->default('ecommerce')
                    ->required()
                    ->live(),
                Forms\Components\Select::make('material')
                    ->options([
                        'gold' => 'Gold', 'silver' => 'Silver', 'vessel' => 'Vessel',
                        'accessory' => 'Accessory', 'diamond' => 'Diamond', 'other' => 'Other',
                    ])
                    ->default('gold')
                    ->required(),
                Forms\Components\Select::make('parent_id')
                    ->label('Parent (leave empty for a top-level category)')
                    ->options(fn (Forms\Get $get) => Category::query()
                        ->whereNull('parent_id')
                        ->when($get('domain'), fn ($q, $d) => $q->where('domain', $d))
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => self::label($c)])
                        ->all())
                    ->searchable(),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(140)
                    ->unique(ignoreRecord: true),
                Forms\Components\FileUpload::make('image_path')->image()->directory('categories'),
                Forms\Components\TextInput::make('sort')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    protected static function label(Category $c): string
    {
        $default = Translatable::defaultLocale();

        return Translatable::pick($c->name, $default);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('domain')->badge()->sortable(),
                Tables\Columns\TextColumn::make('material')->badge(),
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->getStateUsing(fn ($record) => $record->parent ? self::label($record->parent) : '—'),
                Tables\Columns\ImageColumn::make('image_path'),
                Tables\Columns\TextColumn::make('sort')
                    ->numeric()
                    ->sortable(),
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
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
