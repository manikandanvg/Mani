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
use App\Support\Money;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Branch → HQ stock returns. The branch-in-charge raises a return; HQ approves it,
 * which moves the stock to Head Office and credits the voucher amount to the branch
 * Digi cash wallet (spendable on stock orders).
 */
class StockReturnResource extends BaseResource
{
    protected static ?string $model = StockReturn::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Stock Returns';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    public static function getNavigationBadge(): ?string
    {
        $n = StockReturn::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'lines.catalogProduct', 'creator', 'approver']);

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
                // Board 2026-11-27: the branch names the bill the stock came on and attaches a copy.
                Forms\Components\Section::make('Bill the stock came on')->columns(2)->schema([
                    Forms\Components\TextInput::make('bill_no')
                        ->label('Branch bill number')
                        ->helperText('The bill / order number this stock was received on.')
                        ->required()->maxLength(60)
                        ->datalist(fn () => static::billSuggestions()),
                    Forms\Components\FileUpload::make('attachments')
                        ->label('Copy of the bill (photo / PDF)')
                        ->multiple()->maxFiles(5)->maxSize(8192)->appendFiles()
                        ->disk('local')->directory('stock-return-bills')->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->required()
                        ->validationMessages(['required' => 'Attach a photo or PDF of the bill.']),
                ]),
                // Plain (non-relationship) repeater: creation funnels through
                // StockReturnService::submit, which prices lines at the live rate.
                Forms\Components\Repeater::make('lines')
                    ->label('Items to return')
                    ->columns(4)
                    ->schema([
                        Forms\Components\Select::make('catalog_product_id')
                            ->label('Item (your branch stock)')
                            ->options(fn () => static::heldStockOptions())
                            ->searchable()
                            ->required()->live()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('quantity')
                            ->label('No. of pieces')
                            ->numeric()->minValue(0.001)->required()->live(onBlur: true)
                            ->helperText('Cannot exceed what your branch currently holds.'),
                        Forms\Components\Placeholder::make('line_value')->label('Value (rate + MC + WC + GST)')
                            ->content(function (Forms\Get $get) {
                                $q = app(StockReturnService::class)->quote([['catalog_product_id' => $get('catalog_product_id'), 'quantity' => $get('quantity')]]);

                                return $q['lines'] === [] ? '—' : new HtmlString('<b>' . Money::inr($q['total']) . '</b>'
                                    . ' <span class="text-xs text-gray-500">(' . static::num($q['lines'][0]['weight']) . ' g)</span>');
                            }),
                    ])
                    ->addActionLabel('Add item')
                    ->minItems(1)
                    ->live()
                    ->columnSpanFull(),
                // The total the branch is about to send — shown before it goes to Head Office.
                Forms\Components\Section::make('Summary')
                    ->description('Priced at the current live rate with making, wastage and GST. Check it before you send the return to Head Office.')
                    ->schema([
                        Forms\Components\Placeholder::make('summary')->label('')
                            ->content(fn (Forms\Get $get) => static::summaryHtml(
                                app(StockReturnService::class)->quote(array_values((array) $get('lines'))))),
                    ]),
                Forms\Components\Textarea::make('notes')->label('Purpose / reason for the return')
                    ->required()->rows(2)->maxLength(1000)->columnSpanFull(),
            ]);
    }

    /** Item-wise working + totals: metal value, making, wastage, GST, grand total. */
    public static function summaryHtml(array $quote): HtmlString
    {
        if (($quote['lines'] ?? []) === []) {
            return new HtmlString('<span class="text-sm text-gray-500">Add an item and its pieces to see the total.</span>');
        }
        $td = 'padding:6px 8px;border-bottom:1px solid rgba(128,128,128,.2)';
        $r = $td . ';text-align:right;font-variant-numeric:tabular-nums';
        $money = fn ($n) => $n === null ? '—' : Money::inr((float) $n);
        $rows = '';
        foreach ($quote['lines'] as $l) {
            $qty = $l['quantity'] === null ? '—'
                : (($l['unit_weight'] ?? 0) > 0 ? static::num($l['unit_weight']) . ' g × ' . static::num($l['quantity']) : static::num($l['quantity']));
            $rows .= '<tr><td style="' . $td . '">' . e($l['label']) . '</td>'
                . '<td style="' . $r . '">' . e($qty) . '</td>'
                . '<td style="' . $r . '">' . static::num($l['weight']) . ' g</td>'
                . '<td style="' . $r . '">' . $money($l['rate']) . '</td>'
                . '<td style="' . $r . '">' . $money($l['metal_value']) . '</td>'
                . '<td style="' . $r . '">' . $money($l['making']) . '</td>'
                . '<td style="' . $r . '">' . $money($l['wastage']) . '</td>'
                . '<td style="' . $r . '">' . $money($l['gst']) . '</td>'
                . '<td style="' . $r . ';font-weight:600">' . $money($l['line_total']) . '</td></tr>';
        }
        $foot = '<tr style="font-weight:700"><td style="' . $td . '" colspan="4">Total — ' . count($quote['lines']) . ' item(s)</td>'
            . '<td style="' . $r . '">' . $money($quote['metal_value']) . '</td>'
            . '<td style="' . $r . '">' . $money($quote['making']) . '</td>'
            . '<td style="' . $r . '">' . $money($quote['wastage']) . '</td>'
            . '<td style="' . $r . '">' . $money($quote['gst']) . '</td>'
            . '<td style="' . $r . '">' . $money($quote['total']) . '</td></tr>';
        $th = fn (string $t, bool $right = true) => '<th style="padding:6px 8px;text-align:' . ($right ? 'right' : 'left') . '">' . $t . '</th>';

        return new HtmlString('<div class="icl-scroll-x" style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.85rem">'
            . '<thead><tr class="text-gray-500">' . $th('Item', false) . $th('Pieces') . $th('Weight') . $th('Rate / g') . $th('Metal value')
            . $th('Making (MC)') . $th('Wastage (WC)') . $th('GST') . $th('Total') . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table></div>');
    }

    /** The stored lines of a return in the shape summaryHtml() reads (old returns have no break-up). */
    public static function storedQuote(StockReturn $record): array
    {
        $record->loadMissing('lines.catalogProduct');
        $lines = [];
        foreach ($record->lines as $l) {
            $cp = $l->catalogProduct;
            $has = (float) $l->metal_value > 0;
            $lines[] = [
                'label' => $cp ? trim($cp->code . ' — ' . (Translatable::pick($cp->name) ?? '')) : '#' . $l->catalog_product_id,
                'quantity' => $l->quantity !== null ? (float) $l->quantity : null,
                'unit_weight' => $cp?->unitWeight() ?? 0,
                'weight' => (float) $l->weight,
                'rate' => (float) $l->rate,
                'metal_value' => $has ? (float) $l->metal_value : null,
                'making' => $has ? (float) $l->making : null,
                'wastage' => $has ? (float) $l->wastage : null,
                'gst' => $has ? (float) $l->gst : null,
                'line_total' => (float) $l->line_total,
            ];
        }
        $sum = fn (string $k) => collect($lines)->every(fn ($l) => $l[$k] !== null) ? round((float) collect($lines)->sum($k), 2) : null;

        return ['lines' => $lines, 'metal_value' => $sum('metal_value'), 'making' => $sum('making'), 'wastage' => $sum('wastage'),
            'gst' => $sum('gst'), 'total' => (float) $record->total_amount];
    }

    protected static function num($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    }

    /** The branch's own recent order / bill numbers, offered as you type (free text is allowed). */
    protected static function billSuggestions(): array
    {
        $branchId = auth()->user()?->branch_id;
        if (! $branchId) {
            return [];
        }
        $orders = \App\Models\BranchOrderRequest::where('branch_id', $branchId)
            ->whereNotIn('status', ['pending', 'rejected', 'cancelled'])->latest('id')->limit(40)->get(['id', 'request_no']);
        $bills = \Illuminate\Support\Facades\DB::table('branch_order_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'branch_order_lines.sales_invoice_id')
            ->whereIn('branch_order_lines.order_request_id', $orders->pluck('id'))
            ->distinct()->pluck('sales_invoices.invoice_no');

        return $bills->merge($orders->pluck('request_no'))->filter()->unique()->values()->all();
    }

    /** Admin view (board 2026-11-27): what is returned, why, and the attached bill. */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Stock return')->columns(4)->schema([
                Infolists\Components\TextEntry::make('return_no')->label('Voucher'),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch')
                    ->formatStateUsing(fn ($state, StockReturn $r) => $r->branch ? $r->branch->name . ' (ID ' . $r->branch_id . ')' : '—'),
                Infolists\Components\TextEntry::make('creator.name')->label('Raised by')->placeholder('—'),
                Infolists\Components\TextEntry::make('bill_no')->label('Branch bill number')->placeholder('— not given —')->copyable(),
                Infolists\Components\TextEntry::make('created_at')->label('Raised')->dateTime(),
                Infolists\Components\TextEntry::make('approved_at')->label('Decided')->dateTime()->placeholder('—'),
                Infolists\Components\TextEntry::make('approver.name')->label('Decided by')->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->label('Purpose / reason')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Items')->schema([
                Infolists\Components\TextEntry::make('items')->label('')
                    ->state(fn (StockReturn $r) => static::summaryHtml(static::storedQuote($r))),
            ]),
            Infolists\Components\Section::make('Attached bill')->schema([
                Infolists\Components\TextEntry::make('files')->label('')
                    ->state(fn (StockReturn $r) => new HtmlString(collect((array) $r->attachments)->values()
                        ->map(fn ($path, $i) => '<a href="' . e(route('stock-return.attachment', ['stockReturn' => $r->id, 'index' => $i])) . '" target="_blank" rel="noopener"'
                            . ' style="display:inline-block;margin:.15rem .75rem .15rem 0;color:#ab222f;font-weight:600;text-decoration:underline">'
                            . 'Bill copy ' . ($i + 1) . ' (' . e(strtoupper(pathinfo((string) $path, PATHINFO_EXTENSION))) . ')</a>')
                        ->implode('') ?: '<span class="text-sm text-gray-500">No file attached.</span>')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('return_no')->label('Voucher')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable(),
                Tables\Columns\TextColumn::make('bill_no')->label('Branch bill no')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('lines_count')->counts('lines')->label('Items'),
                Tables\Columns\TextColumn::make('files')->label('Bill copy')
                    ->state(fn (StockReturn $r) => count((array) $r->attachments) ?: null)
                    ->icon('heroicon-m-paper-clip')->alignCenter()->placeholder('—'),
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
                // Items with their working, the purpose and the attached bill (board 2026-11-27).
                Tables\Actions\ViewAction::make()->modalWidth('6xl'),
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
            ->where('order_line_id', 0)
            ->with('catalogProduct')
            ->get()
            ->filter(fn ($s) => $s->catalogProduct && ! $s->catalogProduct->is_custom_order)
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
