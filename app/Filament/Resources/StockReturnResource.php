<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Resources\StockReturnResource\Pages;
use App\Models\CatalogProduct;
use App\Models\StockReturn;
use App\Services\StockReturnService;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch → HQ stock returns. The branch-in-charge raises a return; HQ approves it,
 * which moves the stock to Head Office and credits the voucher amount to the branch
 * Digi cash wallet (spendable on stock orders).
 */
class StockReturnResource extends BaseResource
{
    protected static ?string $model = StockReturn::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Stock Returns';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    public static function getNavigationBadge(): ?string
    {
        $n = StockReturn::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'lines.catalogProduct']);

        $user = auth()->user();
        if ($user && method_exists($user, 'isDistributor') && $user->isDistributor() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Plain (non-relationship) repeater: creation funnels through
                // StockReturnService::submit, which prices lines at the live rate.
                Forms\Components\Repeater::make('lines')
                    ->label('Items to return')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('catalog_product_id')
                            ->label('Item (your branch stock)')
                            ->options(fn () => static::heldStockOptions())
                            ->searchable()
                            ->required()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('weight')
                            ->label('Weight (g) / qty')
                            ->numeric()->required()
                            ->helperText('Cannot exceed what your branch currently holds.'),
                    ])
                    ->addActionLabel('Add item')
                    ->minItems(1)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('return_no')->label('Voucher')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable(),
                Tables\Columns\TextColumn::make('lines_count')->counts('lines')->label('Items'),
                Tables\Columns\TextColumn::make('total_amount')->label('Voucher amount')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Raised')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('approved_at')->label('Approved')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
            ])
            ->actions([
                // HQ decision: move the stock home + credit the branch Digi cash wallet.
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (StockReturn $record) => 'Approve ' . $record->return_no
                        . '? The stock moves to Head Office and ₹' . \App\Support\Money::group((float) $record->total_amount)
                        . ' is credited to the branch Digi cash wallet.')
                    ->visible(fn (StockReturn $record) => $record->status === 'pending' && static::isHq())
                    ->action(function (StockReturn $record) {
                        try {
                            app(StockReturnService::class)->approve($record, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Approval failed')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()
                            ->title($record->return_no . ' approved')
                            ->body('Stock moved to Head Office; Digi cash credited to the branch wallet.')
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (StockReturn $record) => $record->status === 'pending' && static::isHq())
                    ->action(function (StockReturn $record) {
                        app(StockReturnService::class)->reject($record, auth()->id());
                        Notification::make()->warning()->title($record->return_no . ' rejected')->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** Only items the current user's branch actually holds (with the held qty in the label). */
    protected static function heldStockOptions(): array
    {
        $branchId = auth()->user()?->branch_id;
        if (! $branchId) {
            return [];
        }

        return \App\Models\Stock::where('branch_id', $branchId)->where('quantity', '>', 0)
            ->with('catalogProduct')
            ->get()
            ->filter(fn ($s) => $s->catalogProduct)
            ->mapWithKeys(fn ($s) => [
                $s->catalog_product_id => $s->catalogProduct->code
                    . ' — ' . Translatable::pick($s->catalogProduct->name)
                    . ' (holds ' . rtrim(rtrim(number_format((float) $s->quantity, 3), '0'), '.') . ')',
            ])->all();
    }

    protected static function isHq(): bool
    {
        $user = auth()->user();

        return $user && (! method_exists($user, 'isDistributor') || ! $user->isDistributor());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockReturns::route('/'),
            'create' => Pages\CreateStockReturn::route('/create'),
        ];
    }
}
