<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\StockTransferMarginResource\Pages;
use App\Models\CatalogProduct;
use App\Models\StockTransferMargin;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Stock-transfer margin rate card per catalog item — the % each seller level earns
 * when it passes that item one hop down the chain.
 */
class StockTransferMarginResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = StockTransferMargin::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationLabel = 'Stock Transfer Margins';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('catalog_product_id')
                ->label('Catalog item')
                ->options(fn () => self::productOptions())
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('One rate card per catalog item.'),
            Forms\Components\Fieldset::make('Margin % by seller level')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('zonal_pct')->label('Zonal %')->numeric()->default(0)->suffix('%'),
                    Forms\Components\TextInput::make('district_pct')->label('District %')->numeric()->default(0)->suffix('%'),
                    Forms\Components\TextInput::make('taluk_pct')->label('Taluk %')->numeric()->default(0)->suffix('%'),
                    Forms\Components\TextInput::make('wholesaler_pct')->label('Wholesaler %')->numeric()->default(0)->suffix('%'),
                    Forms\Components\TextInput::make('reseller_pct')->label('Reseller %')->numeric()->default(0)->suffix('%'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('catalogProduct.name')->label('Name')
                    ->getStateUsing(fn ($record) => Translatable::pick($record->catalogProduct?->name, Translatable::defaultLocale())),
                Tables\Columns\TextColumn::make('catalogProduct.material')->label('Material')->badge(),
                Tables\Columns\TextColumn::make('zonal_pct')->label('Zonal')->suffix('%'),
                Tables\Columns\TextColumn::make('district_pct')->label('District')->suffix('%'),
                Tables\Columns\TextColumn::make('taluk_pct')->label('Taluk')->suffix('%'),
                Tables\Columns\TextColumn::make('wholesaler_pct')->label('Wholesaler')->suffix('%'),
                Tables\Columns\TextColumn::make('reseller_pct')->label('Reseller')->suffix('%'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    /** Catalog items labelled "CODE — Name (material)". */
    protected static function productOptions(): array
    {
        $default = Translatable::defaultLocale();

        return CatalogProduct::query()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (CatalogProduct $p) => [
                $p->id => trim($p->code . ' — ' . Translatable::pick($p->name, $default) . ' (' . $p->material . ')'),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTransferMargins::route('/'),
            'create' => Pages\CreateStockTransferMargin::route('/create'),
            'edit' => Pages\EditStockTransferMargin::route('/{record}/edit'),
        ];
    }
}
