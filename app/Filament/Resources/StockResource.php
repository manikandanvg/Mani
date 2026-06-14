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

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $default = Translatable::defaultLocale();

        return $table
            ->defaultSort('quantity', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Code')->searchable(),
                Tables\Columns\TextColumn::make('catalogProduct.name')->label('Product')
                    ->getStateUsing(fn ($record) => $record->catalogProduct
                        ? Translatable::pick($record->catalogProduct->name, $default)
                        : '—'),
                Tables\Columns\TextColumn::make('catalogProduct.material')->label('Material')->badge(),
                Tables\Columns\TextColumn::make('quantity')->label('Qty / Weight')->numeric(4)->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('purity'),
                Tables\Columns\TextColumn::make('last_rate')->baseMoney(),
                Tables\Columns\TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStock::route('/'),
        ];
    }
}
