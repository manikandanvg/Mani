<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BranchOrderResource\Pages;
use App\Models\BranchOrderRequest;
use App\Services\BranchOrderService;
use App\Services\CustomizeOrderService;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Order Requests — distributors see their own branch's stock orders AND the orders placed
 * with them by the branches they supply; Head Office sees all. The supplier (HQ or the
 * source dealer) can Approve (ships the goods / accepts a customised order) or Reject.
 * Created from the Order Form / Customize Order Form pages, never hand-entered here.
 */
class BranchOrderResource extends BaseResource
{
    use BranchScoped;

    protected static ?string $model = BranchOrderRequest::class;

    protected static ?string $navigationGroup = 'Trade';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Order Requests';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;   // orders are placed via the Order Form page
    }

    protected static function hqApprover(): bool
    {
        $u = auth()->user();

        return $u && ! $u->isDistributor();
    }

    /**
     * Who may decide an order: HQ / admins always; a distributor when the order was
     * placed WITH their branch (board 2026-08-26 — the supplier may itself be a dealer).
     */
    protected static function canDecide(BranchOrderRequest $r): bool
    {
        if (static::hqApprover()) {
            return true;
        }
        $u = auth()->user();

        return $u && $u->branch_id && (int) ($r->branch?->source_branch_id) === (int) $u->branch_id;
    }

    /** May the current user act on a customized order sitting at $branchId? */
    protected static function holds(BranchOrderRequest $r): bool
    {
        $u = auth()->user();
        if (! $u || ! $r->current_branch_id) {
            return false;
        }

        return $u->isDistributor()
            ? (int) $u->branch_id === (int) $r->current_branch_id
            : (int) $r->current_branch_id === CustomizeOrderService::hqBranchId();
    }

    protected static function notify(\Closure $fn, string $okTitle): void
    {
        try {
            $fn();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($okTitle)->send();
    }

    /** Own-branch orders plus orders from branches this branch supplies (distributors); everything for HQ. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'salesReturn', 'member', 'currentBranch']);
        $user = auth()->user();

        if ($user && $user->isDistributor() && $user->branch_id) {
            $mine = (int) $user->branch_id;
            // Own orders, orders placed with me, orders currently with me, and every
            // customized order that travelled through my branch (board 2026-08-27).
            $query->where(fn (Builder $q) => $q
                ->where('branch_id', $mine)
                ->orWhere('current_branch_id', $mine)
                ->orWhereHas('branch', fn (Builder $b) => $b->where('source_branch_id', $mine))
                ->orWhereHas('events', fn (Builder $e) => $e->where('branch_id', $mine)->orWhere('to_branch_id', $mine)));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('request_no')->label('Order #')->searchable()->sortable(),
                // "Customize order" is called out loudly (board 2026-08-26) so a supplier
                // dealer can tell bespoke work from a plain stock restock at a glance.
                Tables\Columns\TextColumn::make('source')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => BranchOrderService::sourceLabel($state))
                    ->icon(fn ($state) => $state === BranchOrderService::SOURCE_CUSTOMIZE ? 'heroicon-m-sparkles' : null)
                    ->color(fn ($state) => match ($state) {
                        BranchOrderService::SOURCE_REDEMPTION => 'info',
                        BranchOrderService::SOURCE_CUSTOMIZE => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('redemptionInvoice.invoice_no')->label('Redeem Inv')
                    ->placeholder('—')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('currentBranch.name')->label('Now at')->placeholder('—')->toggleable()
                    ->description(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->current_branch_id ? match ($r->status) {
                        'pending' => 'awaiting forward / accept', 'approved', 'in_transit' => 'holding the pieces', 'delivered' => 'ready to bill', default => null,
                    } : null),
                Tables\Columns\TextColumn::make('no_of_items')->label('Items')->alignCenter(),
                Tables\Columns\TextColumn::make('grand_total')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('coin_credit')->label('Coins')->baseMoney()
                    ->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('payment_type')->label('Payment')->badge()
                    ->formatStateUsing(fn ($state) => BranchOrderRequest::paymentLabel($state)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state, BranchOrderRequest $r) => match ($state) {
                        'approved' => $r->isCustomize() ? 'Accepted' : 'Approved',
                        'in_transit' => 'In transit', default => ucfirst((string) $state),
                    })
                    ->color(fn ($state) => match ($state) {
                        'approved', 'delivered', 'billed' => 'success', 'in_transit' => 'info',
                        'rejected', 'cancelled' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('requester.name')->label('By')->toggleable(),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->label('Decided')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved / Accepted', 'in_transit' => 'In transit', 'delivered' => 'Delivered', 'billed' => 'Billed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']),
                Tables\Filters\SelectFilter::make('source')->label('Type')
                    ->options([
                        BranchOrderService::SOURCE_ORDER_FORM => 'Order form',
                        BranchOrderService::SOURCE_CUSTOMIZE => 'Customize order',
                        BranchOrderService::SOURCE_REDEMPTION => 'Redemption',
                    ]),
            ])
            ->actions([
                // No 'view' page registered (board phase-1, 2026-08-21) — the same
                // button now opens the infolist below as a popup modal.
                Tables\Actions\ViewAction::make()->modalWidth('5xl'),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-m-check-circle')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (BranchOrderRequest $r) => $r->isCustomize()
                        ? 'Accept this customised order? ' . ((float) $r->coin_credit > 0
                            ? 'The customer\'s coins (' . ($r->salesReturn?->return_no ?? 'sales return') . ') must already be marked collected — they move to you on approval.'
                            : 'No stock moves; you will make the pieces to the description.')
                        : 'Approve this order? The items will be added to the branch stock.')
                    // The payment proof (receipt / bank slip) is shown right in the
                    // confirmation so the approver checks it before approving.
                    ->modalContent(fn (BranchOrderRequest $r) => view('filament.modals.order-attachments', ['record' => $r]))
                    ->visible(fn (BranchOrderRequest $r) => $r->status === 'pending' && ! $r->isCustomize() && static::canDecide($r))
                    ->action(function (BranchOrderRequest $r) {
                        try {
                            app(BranchOrderService::class)->approve($r);
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            Notification::make()->danger()->title('Cannot approve')->body($e->getMessage())->persistent()->send();

                            return;
                        }
                        Notification::make()->success()->title($r->request_no . ' approved')->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-m-x-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (BranchOrderRequest $r) => $r->status === 'pending' && ! $r->isCustomize() && static::canDecide($r))
                    ->action(function (BranchOrderRequest $r) {
                        try {
                            app(BranchOrderService::class)->reject($r);
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            Notification::make()->danger()->title('Cannot reject')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->warning()->title($r->request_no . ' rejected')->send();
                    }),

                // ── Customized orders travel the ladder (board 2026-08-27) ──
                Tables\Actions\Action::make('forward')
                    ->label('Forward')
                    ->icon('heroicon-m-arrow-up-circle')->color('info')
                    ->form([Forms\Components\Textarea::make('note')->label('Note for the next branch (optional)')->rows(2)])
                    ->modalHeading(fn (BranchOrderRequest $r) => 'Forward ' . $r->request_no . ' to '
                        . ($r->currentBranch?->sourceBranch?->name ?? 'Head Office'))
                    ->modalDescription('You cannot make this piece yourself — pass the order up to your supplier. It keeps climbing until Head Office accepts or rejects it.')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->status === 'pending' && static::holds($r)
                        && $r->currentBranch?->level !== 'hq')
                    ->action(fn (BranchOrderRequest $r, array $data) => static::notify(
                        fn () => app(CustomizeOrderService::class)->forward($r, auth()->user(), $data['note'] ?? null),
                        $r->request_no . ' forwarded')),
                Tables\Actions\Action::make('reject_custom')
                    ->label('Reject')
                    ->icon('heroicon-m-x-circle')->color('danger')
                    ->form([Forms\Components\Textarea::make('note')->label('Reason')->rows(2)->required()])
                    ->modalDescription('The ordering branch gets its cash-stock / wallet payment back and any pending coin return is cancelled.')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->status === 'pending'
                        && (static::holds($r) || static::hqApprover()))
                    ->action(fn (BranchOrderRequest $r, array $data) => static::notify(
                        fn () => app(CustomizeOrderService::class)->reject($r, auth()->user(), $data['note'] ?? null),
                        $r->request_no . ' rejected')),
                Tables\Actions\Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-m-check-circle')->color('success')
                    ->modalHeading(fn (BranchOrderRequest $r) => 'Accept ' . $r->request_no . ' — ' . $r->customerName())
                    ->modalDescription('Fix the delivery date, the coin pick-up date and any extra quote. The extra is debited from the ordering branch\'s wallet when it bills the customer.')
                    ->modalContent(fn (BranchOrderRequest $r) => view('filament.modals.order-attachments', ['record' => $r]))
                    ->form([
                        Forms\Components\DatePicker::make('delivery_date')->label('Delivery date')->native(false)->required()
                            ->minDate(now()->toDateString()),
                        Forms\Components\DateTimePicker::make('coin_pickup_on')->label('Coin pick-up (date & time)')->native(false)->seconds(false)
                            ->visible(fn (BranchOrderRequest $r) => (bool) $r->sales_return_id)
                            ->default(fn (BranchOrderRequest $r) => $r->salesReturn?->collect_on),
                        Forms\Components\TextInput::make('quote_extra')->label('Extra quote (₹, if the price has risen)')
                            ->numeric()->minValue(0)->default(0)->prefix('₹')
                            ->helperText(fn (BranchOrderRequest $r) => 'Order value ₹' . \App\Support\Money::group((float) $r->grand_total)
                                . ' was frozen at order time; add only the difference you are quoting.'),
                        Forms\Components\Textarea::make('note')->label('Note')->rows(2),
                    ])
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->status === 'pending' && static::hqApprover()
                        && (int) $r->current_branch_id === CustomizeOrderService::hqBranchId())
                    ->action(fn (BranchOrderRequest $r, array $data) => static::notify(
                        fn () => app(CustomizeOrderService::class)->accept($r, $data, auth()->user()),
                        $r->request_no . ' accepted — pieces added to Head Office stock')),
                Tables\Actions\Action::make('deliver')
                    ->label('Delivery')
                    ->icon('heroicon-m-truck')->color('primary')->requiresConfirmation()
                    ->modalDescription(fn (BranchOrderRequest $r) => 'Send the pieces of ' . $r->request_no . ' to '
                        . (\App\Models\Branch::find(app(CustomizeOrderService::class)->nextHopDown($r, (int) $r->current_branch_id))?->name ?? 'the next branch')
                        . '? Your branch earns its transfer margin on this hop.')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && in_array($r->status, ['approved', 'in_transit'], true) && static::holds($r))
                    ->action(fn (BranchOrderRequest $r) => static::notify(
                        fn () => app(CustomizeOrderService::class)->deliver($r, auth()->user()),
                        $r->request_no . ' pieces sent down the chain')),
                // The ordering branch pulls the pieces the rest of the way (user 2026-08-29:
                // "how does the receiving dealer receive?") — every hop still records its margin.
                Tables\Actions\Action::make('receive')
                    ->label('Receive')
                    ->icon('heroicon-m-inbox-arrow-down')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (BranchOrderRequest $r) => 'The pieces of ' . $r->request_no . ' are with '
                        . ($r->currentBranch?->name ?? 'another branch') . '. Receive them into ' . ($r->branch?->name ?? 'the ordering branch')
                        . ' now? Each branch on the way still earns its transfer margin.')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && in_array($r->status, ['approved', 'in_transit'], true)
                        && (int) $r->current_branch_id !== (int) $r->branch_id
                        && (static::hqApprover() || (int) auth()->user()?->branch_id === (int) $r->branch_id))
                    ->action(fn (BranchOrderRequest $r) => static::notify(
                        fn () => app(CustomizeOrderService::class)->receive($r, auth()->user()),
                        $r->request_no . ' received — the pieces are now in ' . ($r->branch?->name ?? 'the ordering branch') . "'s stock")),
                Tables\Actions\Action::make('coins')
                    ->label('Coin captured')
                    ->icon('heroicon-m-circle-stack')->color('warning')
                    ->form([Forms\Components\Textarea::make('note')->label('Note (who handed over, count checked…)')->rows(2)])
                    ->modalDescription(fn (BranchOrderRequest $r) => 'Confirm Head Office received ' . rtrim(rtrim(number_format((float) ($r->salesReturn?->quantity ?? 0), 4), '0'), '.')
                        . ' coin(s) from ' . ($r->branch?->name ?? 'the branch') . '. They move into Head Office stock.')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->sales_return_id && ! $r->coin_captured_at
                        && ! in_array($r->status, ['rejected', 'cancelled'], true) && static::hqApprover())
                    ->action(fn (BranchOrderRequest $r, array $data) => static::notify(
                        fn () => app(CustomizeOrderService::class)->captureCoins($r, auth()->user(), $data['note'] ?? null),
                        $r->request_no . ' coins captured')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Order')->columns(3)->schema([
                Infolists\Components\TextEntry::make('request_no'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('source')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => BranchOrderService::sourceLabel($state))
                    ->color(fn ($state) => match ($state) {
                        BranchOrderService::SOURCE_REDEMPTION => 'info',
                        BranchOrderService::SOURCE_CUSTOMIZE => 'warning',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('redemptionInvoice.invoice_no')->label('Redeem invoice')->placeholder('—')
                    ->visible(fn (BranchOrderRequest $r) => $r->source === BranchOrderService::SOURCE_REDEMPTION),
                Infolists\Components\TextEntry::make('branch.name')->label('Branch (buyer)'),
                Infolists\Components\TextEntry::make('branch.sourceBranch.name')->label('Supplier')->placeholder('Head Office'),
                Infolists\Components\TextEntry::make('requester.name')->label('Placed by')->placeholder('—'),
                Infolists\Components\TextEntry::make('cross_total')->label('Sub total')->baseMoney(),
                Infolists\Components\TextEntry::make('gst_total')->baseMoney(),
                Infolists\Components\TextEntry::make('grand_total')->label('Total amount')->baseMoney()->weight('bold'),
                Infolists\Components\TextEntry::make('coin_credit')->label('Customer coins credit')->baseMoney()
                    ->visible(fn (BranchOrderRequest $r) => (float) $r->coin_credit > 0),
                Infolists\Components\TextEntry::make('salesReturn.return_no')->label('Sales return')
                    ->formatStateUsing(fn ($state, BranchOrderRequest $r) => $state . ' · ' . ucfirst((string) $r->salesReturn?->status)
                        . ($r->salesReturn?->collect_on ? ' · collect ' . $r->salesReturn->collect_on->format('d M Y H:i') : ''))
                    ->visible(fn (BranchOrderRequest $r) => (bool) $r->sales_return_id),
                Infolists\Components\TextEntry::make('customer')->label('Customer')
                    ->state(fn (BranchOrderRequest $r) => $r->customerName() . ($r->member_id ? '' : ' (new customer)'))
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('customer_details')->label('Customer details')
                    ->state(fn (BranchOrderRequest $r) => collect((array) $r->customer_details)
                        ->except('name')->map(fn ($v, $k) => ucfirst($k) . ': ' . $v)->implode(' · ') ?: '—')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && ! $r->member_id)
                    ->columnSpan(2),
                Infolists\Components\TextEntry::make('pay_cash')->label('Paid from cash stock')->baseMoney()
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('pay_wallet')->label('Paid from branch wallet')->baseMoney()
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('paid_amount')->label('Total paid')->baseMoney()
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('payment_type')->label('Payment')->badge()
                    ->formatStateUsing(fn ($state) => BranchOrderRequest::paymentLabel($state)),
                Infolists\Components\TextEntry::make('payment_remarks')->columnSpan(2)->placeholder('—'),
                Infolists\Components\TextEntry::make('currentBranch.name')->label('Now at')->placeholder('—')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('delivery_date')->label('Delivery date')->date('d M Y')->placeholder('—')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize()),
                Infolists\Components\TextEntry::make('quote_extra')->label('HQ extra quote')->baseMoney()
                    ->formatStateUsing(fn ($state, BranchOrderRequest $r) => '₹' . \App\Support\Money::group((float) $state)
                        . ($r->quote_debited_at ? ' · debited ' . $r->quote_debited_at->format('d M Y') : ' · debited from the branch wallet at billing'))
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && (float) $r->quote_extra > 0),
                Infolists\Components\TextEntry::make('coin_pickup_on')->label('Coin pick-up')->dateTime('d M Y H:i')->placeholder('—')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->sales_return_id),
                Infolists\Components\TextEntry::make('coin_captured_at')->label('Coins captured at HQ')->dateTime('d M Y H:i')->placeholder('not yet')
                    ->visible(fn (BranchOrderRequest $r) => $r->isCustomize() && $r->sales_return_id),
                Infolists\Components\ViewEntry::make('attachments')
                    ->label('Payment proof')
                    ->view('filament.modals.order-attachments')
                    ->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Road map')
                ->description('Every hop this order took — visible to each branch on the road and to Head Office.')
                ->visible(fn (BranchOrderRequest $r) => $r->isCustomize())
                ->schema([
                    Infolists\Components\ViewEntry::make('events')->hiddenLabel()->view('filament.modals.order-timeline'),
                ]),
            // Product NAME, not just the code (board 2026-08-26 item 2.3); customised lines
            // show the distributor's description and the per-gram price build-up.
            Infolists\Components\RepeatableEntry::make('lines')->label('Items')->columns(5)->schema([
                Infolists\Components\TextEntry::make('material')->badge(),
                Infolists\Components\TextEntry::make('catalogProduct.code')->label('Product')
                    ->state(fn ($record) => $record->catalogProduct
                        ? trim($record->catalogProduct->code . ' — ' . (Translatable::pick($record->catalogProduct->name) ?? ''))
                        : ($record->description ?: '—')),
                Infolists\Components\TextEntry::make('weight')->label('Weight (g) / Cash')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 4), '0'), '.')),
                Infolists\Components\TextEntry::make('rate')->label('Rate /g')
                    ->state(fn ($record) => (float) $record->unit_price > 0
                        ? '₹' . \App\Support\Money::group((float) $record->unit_price)
                            . ' (live ₹' . \App\Support\Money::group((float) $record->rate)
                            . ' + margin ₹' . \App\Support\Money::group((float) $record->margin_per_g) . ')'
                            . ' · making ' . rtrim(rtrim(number_format((float) $record->making_charge_pct, 3), '0'), '.') . '%'
                            . ' + wastage ' . rtrim(rtrim(number_format((float) $record->wastage_charge_pct, 3), '0'), '.') . '%'
                            . ' · GST ' . rtrim(rtrim(number_format((float) $record->gst_pct, 2), '0'), '.') . '%'
                        : '₹' . \App\Support\Money::group((float) $record->rate)),
                Infolists\Components\TextEntry::make('line_total')->label('Net total')->baseMoney(),
                Infolists\Components\TextEntry::make('billed_at')->label('Billed')
                    ->state(fn ($record) => $record->billed_at ? $record->billed_at->format('d M Y') . ($record->salesInvoice ? ' · ' . $record->salesInvoice->invoice_no : '') : 'not yet')
                    ->visible(fn ($record) => $record->catalog_product_id === null),
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
