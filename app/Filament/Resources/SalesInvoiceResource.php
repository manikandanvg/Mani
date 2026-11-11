<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\SalesInvoiceResource\Pages;
use App\Filament\Resources\SalesInvoiceResource\RelationManagers;
use App\Models\SalesInvoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesInvoiceResource extends BaseResource
{
    use BranchScoped;
    protected static ?string $model = SalesInvoice::class;

    protected static ?string $navigationGroup = 'Sales & Contracts';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    /**
     * Board 2026-10-31 (8 / 16): a sales invoice is never edited and never deleted. It can
     * only be CANCELLED (super admin), which restores stock and reverses every activity of
     * the bill; dealer logins just see their own bills.
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;   // bills are raised on Trade → Sales
    }

    public static function canCancel(SalesInvoice $record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin() && $record->status !== 'cancelled';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('invoice_no')
                    ->required()
                    ->maxLength(40),
                Forms\Components\DatePicker::make('date')
                    ->required(),
                Forms\Components\TextInput::make('customer_member_id')
                    ->label('Distributor ID')
                    ->numeric(),
                Forms\Components\TextInput::make('customer_name')
                    ->label('Distributor name')
                    ->maxLength(200),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
                Forms\Components\TextInput::make('cross_total')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('discount')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('net_total')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('sgst')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('cgst')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('grand_total')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('received')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('payment_type')
                    ->required(),
                Forms\Components\TextInput::make('remarks')
                    ->required(),
                // Cart items — the sale's actual lines, editable like the Sales screen
                // until the invoice's stock QR is redeemed (canEdit gate above).
                Forms\Components\Section::make('Cart items')->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship()
                        ->columns(5)
                        ->schema([
                            Forms\Components\TextInput::make('description')->columnSpan(2),
                            Forms\Components\TextInput::make('qty')->label('Qty / weight')->numeric(),
                            Forms\Components\TextInput::make('making')->numeric(),
                            Forms\Components\TextInput::make('wastage')->numeric(),
                            Forms\Components\TextInput::make('line_total')->numeric()->required(),
                        ])
                        ->addActionLabel('Add item')
                        ->defaultItems(0),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')
                    ->searchable(),
                // Contract-only schemes (is_invoice = false, e.g. P210/P200/P212/P202)
                // record the billing here for accounting, but issue no tax invoice —
                // the customer's document is the contract.
                Tables\Columns\TextColumn::make('document')
                    ->label('Document')
                    ->badge()
                    ->state(fn ($record) => $record->plan?->is_invoice ?? true ? 'Tax invoice' : 'Contract only')
                    ->color(fn (string $state) => $state === 'Tax invoice' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_member_id')
                    ->label('Distributor ID')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('buyer_gst')->label('B2B / B2C')->badge()
                    ->getStateUsing(fn ($record) => $record->buyer_gst ? 'B2B' : 'B2C')
                    ->color(fn ($state) => $state === 'B2B' ? 'info' : 'gray')
                    ->tooltip(fn ($record) => $record->buyer_gst)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Distributor name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cross_total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('discount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sgst')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cgst')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency_code')
                    ->label('Ccy')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('grand_total')
                    ->money(fn ($record) => $record->currency_code ?: 'INR')   // shown in the invoice's own currency
                    ->sortable(),
                Tables\Columns\TextColumn::make('received')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_type'),
                Tables\Columns\TextColumn::make('remarks'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (?string $state) => ucfirst((string) ($state ?: 'active')))
                    ->color(fn (?string $state) => $state === 'cancelled' ? 'danger' : 'success')
                    ->description(fn (SalesInvoice $r) => $r->status === 'cancelled' && $r->cancelled_at
                        ? 'Cancelled ' . $r->cancelled_at->format('d M Y') . ($r->cancel_reason ? ' — ' . $r->cancel_reason : '')
                        : null),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordClasses(fn (SalesInvoice $r) => $r->status === 'cancelled' ? 'opacity-60 line-through' : null)
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'cancelled' => 'Cancelled']),
                \App\Filament\Support\CommonFilters::branch(),
                \App\Filament\Support\CommonFilters::dateRange('date', 'Billed'),
                Tables\Filters\TernaryFilter::make('b2b')
                    ->label('B2B / B2C')
                    ->placeholder('All')
                    ->trueLabel('B2B (buyer GSTIN)')
                    ->falseLabel('B2C (consumer)')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('buyer_gst'),
                        false: fn ($q) => $q->whereNull('buyer_gst'),
                    ),
                Tables\Filters\TernaryFilter::make('is_invoice')
                    ->label('Document')
                    ->placeholder('All')
                    ->trueLabel('Tax invoice')
                    ->falseLabel('Contract only')
                    ->queries(
                        true: fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('is_invoice', true)),
                        false: fn ($q) => $q->whereHas('plan', fn ($p) => $p->where('is_invoice', false)),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('5xl'),
                // "Delete" = CANCEL (board 2026-10-31 item 8): stock comes back, contract /
                // coupons / incentive / margins / volumes / QR of the bill are reversed.
                Tables\Actions\Action::make('cancelInvoice')
                    ->label('Cancel invoice')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (SalesInvoice $r) => static::canCancel($r))
                    ->requiresConfirmation()
                    ->modalHeading(fn (SalesInvoice $r) => 'Cancel invoice ' . $r->invoice_no)
                    ->modalDescription(fn (SalesInvoice $r) => ($why = app(\App\Services\SalesCancelService::class)->blocker($r))
                        ? 'This bill cannot be cancelled: ' . $why
                        : 'The sold stock returns to the branch; the contract, promotional coupons, sales incentive lines, dealer margins, network volumes and the stock QR of this bill are reversed. The bill stays on record as CANCELLED. This cannot be undone.')
                    ->form(fn (SalesInvoice $r) => app(\App\Services\SalesCancelService::class)->blocker($r) ? [] : [
                        Forms\Components\Textarea::make('reason')->label('Reason for cancellation')->required()->rows(2)->maxLength(255),
                        // Legacy "delete entire member data" (user 2026-11-11): the admin decides
                        // whether the member profile is still needed.
                        Forms\Components\Checkbox::make('delete_member')
                            ->label(fn () => 'Also delete the member profile' . ($r->customer ? ' — ' . $r->customer->member_code . ' ' . $r->customer->name : ''))
                            ->disabled(fn () => app(\App\Services\SalesCancelService::class)->memberDeleteBlocker($r) !== null)
                            ->helperText(fn () => ($why = app(\App\Services\SalesCancelService::class)->memberDeleteBlocker($r))
                                ? 'Not possible: ' . $why
                                : 'Tick only when this member was enrolled by mistake. The profile and its app / dealer login are removed; leave unticked to keep the member.'),
                    ])
                    ->modalSubmitAction(fn ($action, SalesInvoice $r) => app(\App\Services\SalesCancelService::class)->blocker($r) ? $action->hidden() : $action)
                    ->action(function (SalesInvoice $r, array $data) {
                        try {
                            $deleteMember = (bool) ($data['delete_member'] ?? false);
                            app(\App\Services\SalesCancelService::class)->cancel($r, auth()->id(), $data['reason'] ?? null, $deleteMember);
                        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            \Filament\Notifications\Notification::make()->danger()
                                ->title('Invoice not cancelled')->body($e->getMessage())->persistent()->send();

                            return;
                        }
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Invoice ' . $r->invoice_no . ' cancelled')
                            ->body('Stock restored; contract, coupons, incentive, margins and QR reversed.'
                                . ($deleteMember ? ' Member profile deleted.' : ''))->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesInvoices::route('/'),
        ];
    }
}
