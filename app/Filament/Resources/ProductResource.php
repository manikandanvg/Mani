<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Category;
use App\Models\Product;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Product::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'Products (E-com)';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translatable::fieldset('name', 'Name'),
                Forms\Components\Section::make()->columns(2)->schema([
                    Forms\Components\TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true),
                    Forms\Components\Select::make('material')
                        ->options(['gold' => 'Gold', 'silver' => 'Silver', 'accessory' => 'Accessory', 'diamond' => 'Diamond', 'other' => 'Other'])
                        ->default('gold')->required(),
                    Forms\Components\Select::make('category_id')
                        ->label('Category')
                        ->options(fn () => self::ecomCategoryOptions(false))
                        ->searchable()->required(),
                    Forms\Components\Select::make('subcategory_id')
                        ->label('Sub-category')
                        ->options(fn () => self::ecomCategoryOptions(true))
                        ->searchable(),
                    Forms\Components\TextInput::make('weight')->label('Weight (g)')->numeric(),
                    Forms\Components\TextInput::make('stone_weight')->label('Stone weight (g)')->numeric(),
                    Forms\Components\TextInput::make('purity')->placeholder('22K / 916 / 925')->maxLength(12),
                ]),
                Forms\Components\Section::make('Pricing & charges')->columns(3)->schema([
                    Forms\Components\TextInput::make('base_price')->numeric(),
                    Forms\Components\TextInput::make('making_charge_pct')->label('Making %')->numeric(),
                    Forms\Components\TextInput::make('wastage_charge_pct')->label('Wastage %')->numeric(),
                    Forms\Components\TextInput::make('hallmark_charge')->label('Hallmark charge')->numeric(),
                    Forms\Components\TextInput::make('gst_pct')->label('GST %')->numeric(),
                    Forms\Components\TextInput::make('stock_qty')->numeric()->default(0),
                ]),
                Translatable::fieldset('description', 'Description', textarea: true, requiredDefault: false),
                Forms\Components\Section::make()->columns(2)->schema([
                    Forms\Components\Toggle::make('is_featured')->label('Featured on homepage')->default(false),
                    Forms\Components\Toggle::make('is_active')->default(true),
                ]),
            ]);
    }

    /** E-commerce-domain categories ($sub = true for sub-categories). */
    protected static function ecomCategoryOptions(bool $sub): array
    {
        $default = Translatable::defaultLocale();

        return Category::query()
            ->where('domain', 'ecommerce')
            ->when($sub, fn ($q) => $q->whereNotNull('parent_id'), fn ($q) => $q->whereNull('parent_id'))
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => Translatable::pick($c->name, $default)])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('category.slug')
                    ->label('Category')
                    ->sortable(),
                Tables\Columns\TextColumn::make('material'),
                Tables\Columns\TextColumn::make('base_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('making_charge_pct')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('wastage_charge_pct')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gst_pct')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock_qty')
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
                Tables\Columns\TextColumn::make('deleted_at')
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
