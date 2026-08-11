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

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    /** An invoice is editable only BEFORE its stock QR is redeemed. */
    public static function canEdit($record): bool
    {
        return ! \App\Models\RedeemableQr::where('invoice_no', $record->invoice_no)
            ->where('status', 'redeemed')->exists();
    }

    /** Deleting a sale is a super-admin-only operation. */
    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
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
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'create' => Pages\CreateSalesInvoice::route('/create'),
            'edit' => Pages\EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}
