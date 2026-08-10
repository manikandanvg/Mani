<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\StockResource\Pages;
use App\Models\Branch;
use App\Models\Stock;
use App\Support\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Trade 2.2 Stock — accumulated holdings per branch per catalog product. Read-only:
 * quantities change only through Purchase (in) and Sales (out).
 */
class StockResource extends BaseResource
{
    use BranchScoped;
    protected static ?string $model = Stock::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $default = Translatable::defaultLocale();

        return $table
            ->defaultSort('quantity', 'desc')
            // Grouped by material with per-group totals: gold grams, silver grams and
            // cash ₹ each get their own subtotal (board ask 2026-08-10: "how much gram
            // of gold available"). Ungroup via the dropdown for the flat list.
            ->groups([
                Tables\Grouping\Group::make('catalogProduct.material')
                    ->label('Material')
                    ->getTitleFromRecordUsing(fn ($record) => strtoupper((string) $record->catalogProduct?->material)),
            ])
            ->defaultGroup('catalogProduct.material')
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Code')->searchable(),
                Tables\Columns\TextColumn::make('catalogProduct.name')->label('Product')
                    ->getStateUsing(fn ($record) => $record->catalogProduct
                        ? Translatable::pick($record->catalogProduct->name, $default)
                        : '—'),
                Tables\Columns\TextColumn::make('catalogProduct.material')->label('Material')->badge(),
                Tables\Columns\TextColumn::make('quantity')->label('Pieces / ₹')->numeric(4)->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    // stock.quantity = pieces (cash = ₹); group totals show both units
                    ->summarize([
                        Tables\Columns\Summarizers\Sum::make()->label('Total pcs')->numeric(4),
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total weight')
                            ->using(fn ($query) => number_format((float) $query
                                ->join('catalog_products', 'catalog_products.id', '=', 'stock.catalog_product_id')
                                ->sum(\Illuminate\Support\Facades\DB::raw(
                                    'stock.quantity * COALESCE(catalog_products.default_weight, 0)'
                                )), 3) . ' g'),
                    ]),
                Tables\Columns\TextColumn::make('weight_equiv')->label('Weight (g)')
                    ->getStateUsing(fn ($record) => $record->catalogProduct && $record->catalogProduct->material !== 'cash'
                        ? number_format($record->catalogProduct->gramsFromPieces((float) $record->quantity), 3)
                        : '—'),
                Tables\Columns\TextColumn::make('purity'),
                Tables\Columns\TextColumn::make('last_rate')->baseMoney(),
                Tables\Columns\TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    // HQ logins open on THEIR OWN (HQ) stock by default (board
                    // 2026-08-10); switch or clear the filter to inspect any branch.
                    // Dealers are BranchScoped anyway, so no default for them.
                    ->default(auth()->user()?->isDistributor()
                        ? null
                        : (auth()->user()?->branch_id ?? \App\Services\SalesService::HQ_BRANCH_ID)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStock::route('/'),
        ];
    }
}
