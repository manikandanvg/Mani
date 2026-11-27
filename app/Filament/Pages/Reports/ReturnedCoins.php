<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\HqOnly;
use App\Models\Branch;
use App\Models\CatalogProduct;
use App\Models\SalesReturn;
use App\Support\Money;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report → Returned Coins (user 2026-11-28). Every coin a sales return has brought back and the
 * admin has confirmed ("Mark collected"): which coin, from whom, on which bill, what it was worth
 * and where it went — Head Office stock for a return against a bill, the branch's stock (then
 * the supplier) for one raised by the Customize Order Form. Returned coins join the normal stock
 * of their item, so this list is the only place they are told apart from new coins.
 */
class ReturnedCoins extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Report';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Returned Coins';

    protected static ?string $title = 'Returned Coins';

    protected static string $view = 'filament.pages.returned-coins';

    /** Where the coins of a return are now. */
    public static function wentTo(SalesReturn $r): string
    {
        return match (true) {
            $r->settled_at !== null => 'Head Office stock',
            $r->status === SalesReturn::STATUS_RELAYED => 'Supplier (order approved)',
            default => 'Branch stock',
        };
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => Money::inr((float) $state);
        $sum = fn () => Tables\Columns\Summarizers\Sum::make()->label('')->formatStateUsing(fn ($state) => Money::inr((float) $state));

        return $table
            ->query(SalesReturn::query()
                ->whereIn('status', [SalesReturn::STATUS_COLLECTED, SalesReturn::STATUS_RELAYED])
                ->with(['branch', 'member', 'catalogProduct']))
            ->defaultSort('collected_at', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('collected_at')->label('Collected')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('return_no')->label('Return #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('member.member_code')->label('Distributor')
                    ->description(fn (SalesReturn $r) => $r->member?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member',
                        fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('bill_no')->label('Bill no')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('catalogProduct.code')->label('Coin')
                    ->formatStateUsing(fn ($state, SalesReturn $r) => $r->catalogProduct
                        ? $r->catalogProduct->code . ' — ' . Translatable::pick($r->catalogProduct->name) : '—'),
                Tables\Columns\TextColumn::make('material')->label('Metal')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst((string) $state))
                    ->color(fn ($state) => $state === 'gold' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('quantity')->label('Coins')->numeric(decimalPlaces: 0)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(decimalPlaces: 0)),
                Tables\Columns\TextColumn::make('grams')->label('Grams')->numeric(decimalPlaces: 3)->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(decimalPlaces: 3)),
                Tables\Columns\TextColumn::make('rate')->label('Rate ₹/g')->formatStateUsing($money)->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('credit_value')->label('Coin value')->formatStateUsing($money)->alignEnd()->sortable()->summarize($sum()),
                Tables\Columns\TextColumn::make('convenience_charge')->label('Convenience charge')->formatStateUsing($money)->alignEnd()->summarize($sum()),
                Tables\Columns\TextColumn::make('settled_amount')->label('Credited to branch Digi cash')
                    ->formatStateUsing($money)->placeholder('—')->alignEnd()->sortable()->summarize($sum()),
                Tables\Columns\TextColumn::make('went_to')->label('Coins went to')->badge()
                    ->state(fn (SalesReturn $r) => static::wentTo($r))
                    ->color(fn ($state) => $state === 'Head Office stock' ? 'success' : 'info'),
            ])
            ->filters([
                Tables\Filters\Filter::make('collected')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Collected from')->native(false),
                        Forms\Components\DatePicker::make('to')->label('Collected to')->native(false),
                    ])->columns(2)
                    // NB: the parameter MUST be named $query — Filament injects it by name.
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('collected_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('collected_at', '<=', $d))),
                Tables\Filters\SelectFilter::make('went_to')->label('Coins went to')
                    ->options(['hq' => 'Head Office stock', 'branch' => 'Branch stock / supplier'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'hq' => $query->whereNotNull('settled_at'),
                        'branch' => $query->whereNull('settled_at'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('branch_id')->label('Branch')
                    ->options(fn () => Branch::fullOptions(true))->searchable(),
                Tables\Filters\SelectFilter::make('material')->label('Metal')->options(['gold' => 'Gold', 'silver' => 'Silver']),
                Tables\Filters\SelectFilter::make('catalog_product_id')->label('Coin')
                    ->options(fn () => CatalogProduct::whereIn('id', SalesReturn::query()->select('catalog_product_id')->whereNotNull('catalog_product_id'))
                        ->get()->mapWithKeys(fn (CatalogProduct $p) => [$p->id => $p->code . ' — ' . Translatable::pick($p->name)])->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
