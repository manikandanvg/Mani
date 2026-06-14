<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\LiveRate;
use App\Models\Vendor;
use App\Services\TradePurchaseService;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Trade 2.1 Purchase + 2.1.1 Purchase Manager. Head Office procurement: buys catalog items
 * from a VENDOR at the live rate, accumulating into stock (CreatePurchase → TradePurchaseService).
 * HQ-only — distributors never buy from outside vendors; they get stock by raising an Order
 * Request to their upstream branch (Order Form → Order Requests → stock transfer).
 */
class PurchaseResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = \App\Models\Purchase::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        $rate = LiveRate::latestFor('IN');

        return $form->schema([
            Forms\Components\Section::make('Purchase')->columns(3)->schema([
                Forms\Components\TextInput::make('ref_no')->placeholder('Auto-generated')->maxLength(40),
                Forms\Components\DatePicker::make('purchase_date')->default(now())->required(),
                Forms\Components\Select::make('vendor_id')->label('Vendor')
                    ->options(fn () => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required(),
                // Procurement always lands in Head-Office stock — no branch choice.
                Forms\Components\Hidden::make('branch_id')->default(fn () => self::hqBranchId()),
                Forms\Components\Placeholder::make('stock_destination')
                    ->label('Stock destination')
                    ->content('Head Office'),
                Forms\Components\Select::make('payment_type')
                    ->options(['cash' => 'Cash', 'online' => 'Online', 'cheque' => 'Cheque'])->default('cash'),
                Forms\Components\TextInput::make('gold_rate')->label('Gold rate /g')->numeric()
                    ->default($rate?->gold ?? 0),
                Forms\Components\TextInput::make('silver_rate')->label('Silver rate /g')->numeric()
                    ->default($rate?->silver ?? 0),
                Forms\Components\Textarea::make('notes')->columnSpanFull()->rows(2),
            ]),
            Forms\Components\Repeater::make('lines')
                ->label('Items')
                ->schema([
                    Forms\Components\Select::make('catalog_product_id')->label('Product')
                        ->options(fn () => self::catalogOptions())
                        ->searchable()->required()->live()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $p = CatalogProduct::find($state);
                            if (! $p) {
                                return;
                            }
                            $set('material', $p->material);
                            $set('purity', $p->default_purity);
                            $set('weight', $p->default_weight);
                            $set('making_charge_pct', $p->making_charge_pct);
                            $set('wastage_charge_pct', $p->wastage_charge_pct);
                            $set('hallmark_charge', $p->hallmark_charge);
                            $set('gst_pct', $p->gst_pct);
                            // prefill the rate from the captured live rate by material
                            $set('rate', $p->material === 'silver' ? ($get('../../silver_rate') ?? 0) : ($get('../../gold_rate') ?? 0));
                        }),
                    Forms\Components\Select::make('material')
                        ->options(['gold' => 'Gold', 'silver' => 'Silver', 'vessel' => 'Vessel'])->default('gold')->required(),
                    Forms\Components\TextInput::make('weight')->label('Weight/Qty')->numeric()->required(),
                    Forms\Components\TextInput::make('purity')->maxLength(12),
                    Forms\Components\TextInput::make('rate')->label('Rate /g')->numeric()->required(),
                    Forms\Components\TextInput::make('making_charge_pct')->label('Making %')->numeric()->default(0),
                    Forms\Components\TextInput::make('wastage_charge_pct')->label('Wastage %')->numeric()->default(0),
                    Forms\Components\TextInput::make('hallmark_charge')->label('Hallmark')->numeric()->default(0),
                    Forms\Components\TextInput::make('gst_pct')->label('GST %')->numeric()->default(3),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->defaultItems(1)
                ->addActionLabel('Add item')
                ->reorderable(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('purchase_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ref_no')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('purchase_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                Tables\Columns\TextColumn::make('lines_count')->counts('lines')->label('Items'),
                Tables\Columns\TextColumn::make('grand_total')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('payment_type')->badge(),
            ])
            ->filters([
                Tables\Filters\Filter::make('month')
                    ->form([Forms\Components\DatePicker::make('month')->displayFormat('M Y')->native(false)])
                    ->query(function ($query, array $data) {
                        if (! $data['month']) {
                            return $query;
                        }
                        $d = \Illuminate\Support\Carbon::parse($data['month']);

                        return $query->whereYear('purchase_date', $d->year)->whereMonth('purchase_date', $d->month);
                    })
                    ->indicateUsing(fn (array $data) => $data['month'] ? 'Month: ' . \Illuminate\Support\Carbon::parse($data['month'])->format('M Y') : null),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Purchase')->columns(3)->schema([
                Infolists\Components\TextEntry::make('ref_no'),
                Infolists\Components\TextEntry::make('purchase_date')->date(),
                Infolists\Components\TextEntry::make('vendor.name')->label('Vendor'),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch'),
                Infolists\Components\TextEntry::make('payment_type')->badge(),
                Infolists\Components\TextEntry::make('grand_total')->baseMoney(),
            ]),
            Infolists\Components\RepeatableEntry::make('lines')->label('Items')->columns(4)->schema([
                Infolists\Components\TextEntry::make('catalogProduct.code')->label('Product'),
                Infolists\Components\TextEntry::make('material')->badge(),
                Infolists\Components\TextEntry::make('weight')->label('Wt/Qty'),
                Infolists\Components\TextEntry::make('purity'),
                Infolists\Components\TextEntry::make('rate')->baseMoney(),
                Infolists\Components\TextEntry::make('line_total')->baseMoney(),
            ]),
        ]);
    }

    /** Head-Office branch id (procurement destination). */
    protected static function hqBranchId(): ?int
    {
        return Branch::where('level', 'hq')->value('id') ?? Branch::min('id');
    }

    /** Trade catalog products as code — name options. */
    protected static function catalogOptions(): array
    {
        $default = Translatable::defaultLocale();

        return CatalogProduct::where('is_active', true)->get()
            ->mapWithKeys(fn ($p) => [
                $p->id => $p->code . ' — ' . Translatable::pick($p->name, $default),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'view' => Pages\ViewPurchase::route('/{record}'),
        ];
    }
}
