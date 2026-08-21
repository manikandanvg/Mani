<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BranchOrderResource\Pages;
use App\Models\BranchOrderRequest;
use App\Services\BranchOrderService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Order Requests — distributors see their own branch's stock orders; Head Office sees all
 * and can Approve (adds the items to the branch's stock) or Reject. Created from the Order
 * Form page, never hand-entered here.
 */
class BranchOrderResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = BranchOrderRequest::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Order Requests';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;   // orders are placed via the Order Form page
    }

    protected static function hqApprover(): bool
    {
        $u = auth()->user();

        return $u && ! $u->isDistributor();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('request_no')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('source')->label('Source')->badge()
                    ->formatStateUsing(fn ($state) => $state === BranchOrderService::SOURCE_REDEMPTION ? 'Redemption' : 'Order form')
                    ->color(fn ($state) => $state === BranchOrderService::SOURCE_REDEMPTION ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('redemptionInvoice.invoice_no')->label('Redeem Inv')
                    ->placeholder('—')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('no_of_items')->label('Items')->alignCenter(),
                Tables\Columns\TextColumn::make('grand_total')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('payment_type')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'rejected', 'cancelled' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('requester.name')->label('By')->toggleable(),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->label('Decided')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']),
                Tables\Filters\SelectFilter::make('source')
                    ->options([BranchOrderService::SOURCE_ORDER_FORM => 'Order form', BranchOrderService::SOURCE_REDEMPTION => 'Redemption']),
            ])
            ->actions([
                // No 'view' page registered (board phase-1, 2026-08-21) — the same
                // button now opens the infolist below as a popup modal.
                Tables\Actions\ViewAction::make()->modalWidth('5xl'),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-m-check-circle')->color('success')->requiresConfirmation()
                    ->modalDescription('Approve this order? The items will be added to the branch stock.')
                    // The payment proof (receipt / bank slip) is shown right in the
                    // confirmation so the approver checks it before approving.
                    ->modalContent(fn (BranchOrderRequest $r) => view('filament.modals.order-attachments', ['record' => $r]))
                    ->visible(fn (BranchOrderRequest $r) => $r->status === 'pending' && static::hqApprover())
                    ->action(fn (BranchOrderRequest $r) => app(BranchOrderService::class)->approve($r)),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-m-x-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (BranchOrderRequest $r) => $r->status === 'pending' && static::hqApprover())
                    ->action(fn (BranchOrderRequest $r) => app(BranchOrderService::class)->reject($r)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Order')->columns(3)->schema([
                Infolists\Components\TextEntry::make('request_no'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('source')->badge()
                    ->formatStateUsing(fn ($state) => $state === BranchOrderService::SOURCE_REDEMPTION ? 'Redemption' : 'Order form'),
                Infolists\Components\TextEntry::make('redemptionInvoice.invoice_no')->label('Redeem invoice')->placeholder('—'),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch'),
                Infolists\Components\TextEntry::make('cross_total')->baseMoney(),
                Infolists\Components\TextEntry::make('gst_total')->baseMoney(),
                Infolists\Components\TextEntry::make('grand_total')->baseMoney(),
                Infolists\Components\TextEntry::make('payment_type')->badge(),
                Infolists\Components\TextEntry::make('payment_remarks')->columnSpan(2)->placeholder('—'),
                Infolists\Components\ViewEntry::make('attachments')
                    ->label('Payment proof')
                    ->view('filament.modals.order-attachments')
                    ->columnSpanFull(),
            ]),
            Infolists\Components\RepeatableEntry::make('lines')->columns(4)->schema([
                Infolists\Components\TextEntry::make('material')->badge(),
                Infolists\Components\TextEntry::make('catalogProduct.code')->label('Product'),
                Infolists\Components\TextEntry::make('weight'),
                Infolists\Components\TextEntry::make('line_total')->baseMoney(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchOrders::route('/'),
        ];
    }
}
