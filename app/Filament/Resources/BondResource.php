<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\BranchScoped;
use App\Filament\Resources\BondResource\Pages;
use App\Filament\Resources\BondResource\RelationManagers;
use App\Models\Bond;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BondResource extends BaseResource
{
    // Distributors see (and show QRs for) their own branch's bonds; HQ sees all.
    use BranchScoped;
    protected static ?string $model = Bond::class;

    protected static ?string $navigationGroup = 'Sales & Bonds';

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('member_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('plan_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('branch_id')
                    ->numeric(),
                Forms\Components\TextInput::make('product_id')
                    ->numeric(),
                Forms\Components\DatePicker::make('bond_date')
                    ->required(),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('invoice_no')
                    ->maxLength(40),
                Forms\Components\TagsInput::make('lvlcom')
                    ->helperText('Captured per-level commission amounts'),
                Forms\Components\TextInput::make('cbc_value')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\TextInput::make('cbc_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('cbc_issued')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('lvlcom_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('lvlcom_issued')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('epin_value')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                Forms\Components\DatePicker::make('return_date'),
                Forms\Components\TextInput::make('mou_id')
                    ->numeric(),
                Forms\Components\Select::make('status')
                    ->options(['active' => 'Active', 'closed' => 'Closed', 'cancelled' => 'Cancelled'])
                    ->default('active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Surface soonest-to-expire bonds first for renewal awareness.
            ->defaultSort('bond_date', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_no')->label('Invoice')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('member.name')->label('Member')->searchable()
                    ->description(fn (Bond $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('plan.code')->label('Plan')->sortable(),
                Tables\Columns\TextColumn::make('value')->baseMoney()->sortable(),
                Tables\Columns\TextColumn::make('bond_date')->label('Start')->date()->sortable(),
                Tables\Columns\TextColumn::make('expires_on')
                    ->label('Expiry')
                    ->state(fn (Bond $r) => $r->expires_on)
                    ->date()
                    ->badge()
                    ->color(fn (Bond $r) => match (true) {
                        ! $r->expires_on => 'gray',
                        $r->expires_on->isPast() => 'danger',
                        $r->expires_on->lte(now()->addDays(30)) => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Bond $r) => $r->expires_on?->diffForHumans()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'cancelled' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'closed' => 'Closed', 'cancelled' => 'Cancelled']),
            ])
            ->actions([
                Tables\Actions\Action::make('contract')
                    ->label('Contract')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (Bond $record) => route('contract.pdf', $record))
                    ->openUrlInNewTab(),
                // Redeemable Stock QR — click to view (minted on first view, one per bond).
                Tables\Actions\Action::make('redeemableQr')
                    ->label('Show QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->modalHeading('Redeemable Stock QR')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Bond $record) {
                        $svc = app(\App\Services\Qr\RedeemableQrService::class);
                        $qr = $svc->forBond($record);

                        return view('filament.redeemable-qr', ['qr' => $qr, 'imageUrl' => $svc->imageUrl($qr)]);
                    }),
                Tables\Actions\Action::make('whatsapp')
                    ->label('Send WhatsApp')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Bond $record) => 'Send the contract PDF and redeemable QR to '
                        . optional($record->member)->name . ' (' . optional($record->member)->phone . ') via WhatsApp?')
                    ->action(function (Bond $record) {
                        if (! optional($record->member)->phone) {
                            \Filament\Notifications\Notification::make()->title('Member has no phone number')->warning()->send();

                            return;
                        }
                        $svc = app(\App\Services\Qr\RedeemableQrService::class);
                        $res = $svc->deliver($svc->forBond($record));

                        \Filament\Notifications\Notification::make()
                            ->title($res['ok'] ? 'Contract & QR sent on WhatsApp' : 'WhatsApp send failed')
                            ->body($res['message'] ?? '')
                            ->status($res['ok'] ? 'success' : 'warning')
                            ->send();
                    }),
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
            'index' => Pages\ListBonds::route('/'),
            'edit' => Pages\EditBond::route('/{record}/edit'),
        ];
    }
}
