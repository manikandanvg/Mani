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
            // Low stock (board 2026-08-13): tint the whole row light danger so a
            // branch spots it at a glance. Styled in public/css/premium.css.
            ->recordClasses(fn (Stock $record) => $record->is_low ? 'icl-stock-low' : null)
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
                // Minimum level — editable straight in the grid (the resource has no
                // edit page because quantity itself is movement-driven, read-only).
                // Read-only everywhere. Both this and the quantity are changed ONLY by
                // Head Office through the "Adjust stock" button (board 2026-08-13).
                Tables\Columns\TextColumn::make('min_qty')
                    ->label('Minimum')
                    ->numeric(4)
                    ->placeholder('—')
                    ->color(fn (Stock $record) => $record->is_low ? 'danger' : null),
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
            ])
            // Head Office only: ONE button sets both the stock and its minimum
            // (board 2026-08-13). Dealers see their figures but change nothing.
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Adjust stock')
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->color('warning')
                    ->visible(fn () => ! auth()->user()?->isDistributor())
                    ->modalHeading(fn (Stock $record) => 'Adjust stock — ' . ($record->branch?->name ?? 'Branch'))
                    ->modalSubmitActionLabel('Save')
                    ->form([
                        \Filament\Forms\Components\Placeholder::make('current')
                            ->label('Current stock')
                            ->content(fn (Stock $record) => rtrim(rtrim(number_format((float) $record->quantity, 4), '0'), '.')),
                        \Filament\Forms\Components\TextInput::make('quantity')
                            ->label('Stock')
                            ->numeric()->required()->minValue(0)
                            ->default(fn (Stock $record) => (float) $record->quantity)
                            ->helperText('The corrected count. Any difference is logged as an adjustment.'),
                        \Filament\Forms\Components\TextInput::make('min_qty')
                            ->label('Minimum stock')
                            ->numeric()->minValue(0)
                            ->default(fn (Stock $record) => $record->min_qty === null ? null : (float) $record->min_qty)
                            ->helperText('The row turns red once stock reaches this level. Leave empty for no minimum.'),
                        \Filament\Forms\Components\TextInput::make('note')
                            ->label('Reason')->maxLength(200)
                            ->placeholder('Physical count, damage, correction…'),
                    ])
                    ->action(function (Stock $record, array $data): void {
                        $new = (float) $data['quantity'];
                        $change = round($new - (float) $record->quantity, 4);
                        $min = ($data['min_qty'] === null || $data['min_qty'] === '') ? null : (float) $data['min_qty'];

                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $new, $min, $change, $data) {
                            $record->update(['quantity' => $new, 'min_qty' => $min]);

                            // A quantity change must leave an audit trail — the stock
                            // ledger has to keep reconciling. Minimum is a setting,
                            // not a movement, so it never writes one.
                            if ($change != 0.0) {
                                \App\Models\StockMovement::create([
                                    'branch_id' => $record->branch_id,
                                    'catalog_product_id' => $record->catalog_product_id,
                                    'type' => 'adjustment',
                                    'qty_change' => $change,
                                    'balance_after' => $new,
                                    'ref_type' => 'hq_adjustment',
                                    'note' => $data['note'] ?? null,
                                    'moved_on' => now()->toDateString(),
                                    'created_by' => auth()->id(),
                                ]);
                            }
                        });

                        \Filament\Notifications\Notification::make()
                            ->title($change == 0.0
                                ? 'Minimum stock updated'
                                : sprintf('Saved — stock %s%s', $change > 0 ? '+' : '', rtrim(rtrim(number_format($change, 4), '0'), '.')))
                            ->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStock::route('/'),
        ];
    }
}
