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

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

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
                    ->numeric(),
                Forms\Components\TextInput::make('customer_name')
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_member_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_name')
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
                //
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
