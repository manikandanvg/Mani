<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Resources\RedemptionInvoiceResource\Pages;
use App\Models\BranchOrderRequest;
use App\Models\RedemptionInvoice;
use App\Services\BranchOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only register of redemption TAX INVOICES (raised by the Redeem-QR screen).
 * Distributors see only their own branch's invoices; HQ sees all. No create/edit —
 * invoices are produced solely by the redemption flow.
 */
class RedemptionInvoiceResource extends BaseResource
{
    protected static ?string $model = RedemptionInvoice::class;

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?string $navigationLabel = 'Redemption Invoices';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['member', 'branch', 'restockOrder', 'lines.catalogProduct']);

        // Branch scope: a distributor login sees only its own branch's invoices.
        $user = auth()->user();
        if ($user && method_exists($user, 'isDistributor') && $user->isDistributor() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('invoice_date')->label('Date')->date()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn ($record) => $record->member?->member_code),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('taxable_total')->label('Taxable')->baseMoney()->toggleable(),
                Tables\Columns\TextColumn::make('grand_total')->label('Grand total')->baseMoney()->sortable(),
                Tables\Columns\IconColumn::make('dealer_created')->label('Dealer A/C')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('payment_mode')->label('Payment')->badge()
                    ->color(fn ($state) => $state === 'pending' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('restock')->label('Restock')->badge()
                    ->state(fn (RedemptionInvoice $r) => static::restockState($r))
                    ->color(fn (string $state) => match ($state) {
                        'Re-Stocked' => 'success',
                        'Pending', 'Not raised' => 'warning',
                        'Rejected' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (RedemptionInvoice $r) => $r->restockOrder?->request_no),
            ])
            ->filters([
                \App\Filament\Support\CommonFilters::branch(),
                \App\Filament\Support\CommonFilters::dateRange('invoice_date', 'Invoiced'),
                Tables\Filters\SelectFilter::make('payment_mode')
                    ->options(['pending' => 'Pending', 'passed' => 'Passed', 'paid' => 'Paid', 'cash' => 'Cash']),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('Tax invoice')
                    ->icon('heroicon-o-printer')
                    ->url(fn (RedemptionInvoice $record) => route('redemption.pdf', $record), shouldOpenInNewTab: true),
                // Restock: the branch gave out stock on this redemption — raise an order to
                // its supplier (HQ) for the same items, which replenishes the branch on approval.
                Tables\Actions\Action::make('restock')
                    ->label('Raise restock order')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Raise restock order')
                    ->modalDescription(fn (RedemptionInvoice $record) => 'Review the items below, then confirm to send this restock order to your supplier. '
                        . 'It appears in Order Requests for approval, which adds the stock back to your branch.')
                    ->modalContent(fn (RedemptionInvoice $record) => static::restockPreview($record))
                    ->modalSubmitActionLabel('Confirm & raise order')
                    ->visible(fn (RedemptionInvoice $record) => static::canRestock($record))
                    ->action(function (RedemptionInvoice $record) {
                        try {
                            $request = app(BranchOrderService::class)->createFromRedemption($record, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not raise restock order')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()
                            ->title('Restock order ' . $request->request_no . ' raised')
                            ->body('Sent to your supplier for approval — the stock is added to your branch once approved.')
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** A restock (re-order) can be raised for any redemption that hasn't been re-ordered yet. */
    protected static function canRestock(RedemptionInvoice $record): bool
    {
        return $record->restockOrder === null && ! static::isCustomized($record);
    }

    /**
     * Board phase 2 (2026-08-28): a QR from a customized-order sale (pieces carried by the
     * CUSTOM-AU / CUSTOM-AG system items) redeems as a plain G10 metal plan and is NEVER
     * restocked — the pieces were made to order, there is nothing to re-order.
     */
    public static function isCustomized(RedemptionInvoice $record): bool
    {
        $record->loadMissing('lines.catalogProduct');

        return $record->lines->contains(fn ($l) => (bool) $l->catalogProduct?->is_custom_order);
    }

    /** Preview of the lines + value the restock order will request (priced at the live rate). */
    protected static function restockPreview(RedemptionInvoice $record): \Illuminate\Support\HtmlString
    {
        $p = app(BranchOrderService::class)->previewFromRedemption($record);

        if ($p['count'] === 0) {
            return new \Illuminate\Support\HtmlString(
                '<div style="padding:.75rem;color:#ab222f">This redemption has no stock items to restock.</div>'
            );
        }

        $fmt = fn ($n) => \App\Support\Money::inr((float) $n);
        $wt = fn ($l) => $l['material'] === 'vessel'
            ? rtrim(rtrim(number_format($l['weight'], 2), '0'), '.') . ' pc'
            : rtrim(rtrim(number_format($l['weight'], 3), '0'), '.') . ' g';

        $rows = '';
        foreach ($p['lines'] as $l) {
            $rows .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #eee">' . e($l['description'])
                . ($l['code'] ? ' <span style="color:#9ca3af">[' . e($l['code']) . ']</span>' : '') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">' . e($wt($l)) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right;font-variant-numeric:tabular-nums">' . $fmt($l['line_total']) . '</td>'
                . '</tr>';
        }

        $foot = fn (string $k, $v, string $extra = '') =>
            '<tr><td colspan="2" style="padding:4px 8px;text-align:right;color:#6b7280;' . $extra . '">' . $k . '</td>'
            . '<td style="padding:4px 8px;text-align:right;font-variant-numeric:tabular-nums;' . $extra . '">' . $fmt($v) . '</td></tr>';

        $html = '<div style="border:1px solid #e5e7eb;border-radius:.6rem;overflow:hidden">'
            . '<table style="width:100%;border-collapse:collapse;font-size:.85rem">'
            . '<thead><tr style="background:#f9fafb">'
            . '<th style="padding:6px 8px;text-align:left">Item</th>'
            . '<th style="padding:6px 8px;text-align:right">Qty</th>'
            . '<th style="padding:6px 8px;text-align:right">Value</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot>'
            . $foot('Cross total', $p['cross'])
            . $foot('GST', $p['gst'])
            . $foot('Grand total', $p['grand'], 'font-weight:700;color:#111827;border-top:1px solid #e5e7eb')
            . '</tfoot>'
            . '</table></div>'
            . '<div style="margin-top:.5rem;font-size:.72rem;color:#9ca3af">Priced at the current live rate · ' . $p['count'] . ' item(s).</div>';

        return new \Illuminate\Support\HtmlString($html);
    }

    /** Badge text for the restock column. */
    protected static function restockState(RedemptionInvoice $record): string
    {
        if ($record->restockOrder === null && static::isCustomized($record)) {
            return 'Customized — no restock';
        }

        return match ($record->restockOrder?->status) {
            'approved' => 'Re-Stocked',
            'pending' => 'Pending',
            'rejected' => 'Rejected',
            default => 'Not raised',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedemptionInvoices::route('/'),
        ];
    }
}
