<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Online Orders — Order Management (board spec 2026-08-09, "instead of Payments").
 * The fulfilment pipeline: placed (pending) → confirmed → packed → shipped →
 * delivered, plus cancel. One-click advance per order, payment status inline;
 * the Payments list left the nav — payment rows live inside each order's view.
 */
class OrderManagement extends Page implements HasTable
{
    use \App\Filament\Concerns\TranslatesNavigation;
    use \App\Filament\Concerns\HqOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Online Orders';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Order Management';

    protected static ?string $title = 'Order Management';

    protected static string $view = 'filament.pages.order-management';

    /** status → next stage. Legacy 'paid' rows continue from the packing step. */
    public const FLOW = [
        'pending' => 'confirmed',
        'confirmed' => 'packed',
        'paid' => 'packed',
        'packed' => 'shipped',
        'shipped' => 'delivered',
    ];

    public const STAGE_LABELS = [
        'pending' => 'Placed',
        'confirmed' => 'Confirmed',
        'paid' => 'Paid (legacy)',
        'packed' => 'Packed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    /** Stage counts for the summary cards across the top of the page. */
    public function getStageCounts(): array
    {
        $counts = Order::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return collect(['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'])
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0) + ($s === 'confirmed' ? (int) ($counts['paid'] ?? 0) : 0)])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->with('payments'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_no')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Placed')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('customer_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('total')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('payment_status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'paid' => 'success',
                        'refunded' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('status')->label('Stage')->badge()
                    ->formatStateUsing(fn (?string $state) => __(self::STAGE_LABELS[$state] ?? ucfirst((string) $state)))
                    ->color(fn (?string $state) => match ($state) {
                        'pending' => 'gray',
                        'confirmed', 'paid' => 'info',
                        'packed' => 'warning',
                        'shipped' => 'primary',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Stage')
                    ->options(collect(self::STAGE_LABELS)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->options(['unpaid' => 'Unpaid', 'paid' => 'Paid', 'refunded' => 'Refunded']),
            ])
            ->actions([
                Tables\Actions\Action::make('advance')
                    ->label(fn (Order $r) => __(self::STAGE_LABELS[self::FLOW[$r->status] ?? ''] ?? 'Advance'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->visible(fn (Order $r) => isset(self::FLOW[$r->status]))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Order $r) => __('Move :order to ":stage"?', [
                        'order' => $r->order_no,
                        'stage' => __(self::STAGE_LABELS[self::FLOW[$r->status]]),
                    ]))
                    ->modalDescription(fn (Order $r) => $r->payment_status !== 'paid'
                        ? __('Note: this order is still UNPAID.')
                        : null)
                    ->action(function (Order $r) {
                        $next = self::FLOW[$r->status] ?? null;
                        if (! $next) {
                            return;
                        }
                        $r->update(['status' => $next]);
                        Notification::make()->success()
                            ->title(__(':order → :stage', ['order' => $r->order_no, 'stage' => __(self::STAGE_LABELS[$next])]))
                            ->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Order $r) => in_array($r->status, ['pending', 'confirmed', 'paid', 'packed'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Order $r) => $r->payment_status === 'paid'
                        ? __('This order is PAID — refund it via the gateway separately; cancelling here only updates the stage.')
                        : null)
                    ->action(function (Order $r) {
                        $r->update(['status' => 'cancelled']);
                        Notification::make()->warning()->title(__(':order cancelled', ['order' => $r->order_no]))->send();
                    }),
                Tables\Actions\Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $r) => OrderResource::getUrl('view', ['record' => $r])),
            ]);
    }
}
