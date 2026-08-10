<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\CatalogProductResource\Pages;
use App\Models\CatalogProduct;
use App\Models\Category;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CatalogProductResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = CatalogProduct::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'Catalog (Gold/Silver/Vessel)';

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(40)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('material')
                    ->options(['gold' => 'Gold', 'silver' => 'Silver', 'vessel' => 'Vessel'])
                    ->default('gold')
                    ->required(),
                Translatable::fieldset('name', 'Name'),
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->options(fn () => self::tradeCategoryOptions(null))
                    ->searchable(),
                Forms\Components\Select::make('subcategory_id')
                    ->label('Sub-category')
                    ->options(fn () => self::tradeCategoryOptions('sub'))
                    ->searchable(),
                Forms\Components\TextInput::make('default_weight')
                    ->label('Default weight (g)')
                    ->helperText('Per-piece grams — billing & redeem pre-fill this (100 mg = 0.1)')
                    ->numeric()
                    // Without it a "100 mg" coin bills as whatever the operator types —
                    // usually 1 g. Cash products have no weight.
                    ->requiredUnless('material', 'cash'),
                Forms\Components\TextInput::make('default_purity')
                    ->placeholder('22K / 916 / 925')
                    ->maxLength(12),
                Forms\Components\TextInput::make('making_charge_pct')->label('Making %')->numeric()->default(0),
                Forms\Components\TextInput::make('wastage_charge_pct')->label('Wastage %')->numeric()->default(0),
                Forms\Components\TextInput::make('hallmark_charge')->label('Additional / Hallmark charge')->numeric()->default(0),
                Forms\Components\TextInput::make('gst_pct')->label('GST %')->numeric()->default(3),
                Forms\Components\TextInput::make('hsn_code')->label('HSN code')->maxLength(20),
                Forms\Components\TextInput::make('gm_margin')
                    ->label('GM redemption margin (₹/gram)')
                    ->numeric()->default(0)->prefix('₹')
                    ->helperText('Flat ₹ per gram dealer margin on redemption (gold or silver, per this product).'),
                Forms\Components\FileUpload::make('image_path')->image()->directory('catalog'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('material')->badge()->sortable(),
                Tables\Columns\TextColumn::make('default_weight')->label('Wt (g)')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('default_purity')->label('Purity'),
                Tables\Columns\TextColumn::make('gst_pct')->label('GST %')->numeric(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    /** Trade-domain categories ($scope = 'sub' for sub-categories, null for roots). */
    protected static function tradeCategoryOptions(?string $scope): array
    {
        $default = Translatable::defaultLocale();

        return Category::query()
            ->where('domain', 'trade')
            ->when($scope === 'sub', fn ($q) => $q->whereNotNull('parent_id'))
            ->when($scope !== 'sub', fn ($q) => $q->whereNull('parent_id'))
            ->get()
            ->mapWithKeys(fn ($c) => [
                $c->id => Translatable::pick($c->name, $default),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogProducts::route('/'),
            'create' => Pages\CreateCatalogProduct::route('/create'),
            'edit' => Pages\EditCatalogProduct::route('/{record}/edit'),
        ];
    }
}
