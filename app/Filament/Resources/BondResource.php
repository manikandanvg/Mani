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

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    /**
     * Board fix 2026-08-09: a bond is a financial instrument — dealer logins may view,
     * print contracts and send QRs, but never edit or delete. Enforced here (not just
     * hidden buttons) so direct URLs are rejected too.
     */
    public static function canEdit($record): bool
    {
        return ! (auth()->user()?->isDistributor() ?? true);
    }

    public static function canDelete($record): bool
    {
        return ! (auth()->user()?->isDistributor() ?? true);
    }

    public static function canDeleteAny(): bool
    {
        return ! (auth()->user()?->isDistributor() ?? true);
    }

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
                Tables\Columns\TextColumn::make('member.name')->label('Distributor')->searchable()
                    ->description(fn (Bond $r) => $r->member?->member_code),
                Tables\Columns\TextColumn::make('plan.code')->label('Scheme')->sortable(),
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
                    ->visible(fn (Bond $record) => (bool) $record->plan?->is_contract)
                    ->url(fn (Bond $record) => route('contract.pdf', $record))
                    ->openUrlInNewTab(),
                // Redeemable Stock QR — click to view (minted on first view, one per bond).
                // Both QR actions only exist for plans flagged is_redeem (schema v2).
                Tables\Actions\Action::make('redeemableQr')
                    ->label('Show QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->visible(fn (Bond $record) => (bool) $record->plan?->is_redeem)
                    ->modalHeading('Redeemable Stock QRs')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (Bond $record) {
                        $svc = app(\App\Services\Qr\RedeemableQrService::class);
                        $svc->forBond($record);   // guarantees the billing QR exists

                        // ALL of the bond's QRs — billing first, then every renewal QR
                        // (board 2026-08-10: renewal QRs were minted but never shown).
                        $qrs = \App\Models\RedeemableQr::where('bond_id', $record->id)
                            ->orderBy('id')
                            ->get()
                            ->values()
                            ->map(fn ($qr, $i) => [
                                'qr' => $qr,
                                'imageUrl' => $svc->imageUrl($qr),
                                // PLUS2-style plans have no billing QR — their one QR
                                // arrives with the first renewal.
                                'label' => match (true) {
                                    $record->plan?->rd_qr_on === 'first_renewal' => 'First-renewal QR — ' . $qr->created_at?->format('d M Y'),
                                    $i === 0 => 'Billing QR',
                                    default => "Renewal QR #{$i} — " . $qr->created_at?->format('d M Y'),
                                },
                            ]);

                        // Mode-A (gold/silver purchase) QRs redeem ONLY at the billing
                        // branch; savings (Mode-B) QRs redeem at any dealer.
                        $branchLocked = app(\App\Services\Redeem\RedemptionService::class)
                            ->isMetalPurchasePlan($record->plan);

                        return view('filament.redeemable-qr-list', [
                            'qrs' => $qrs,
                            'redeemNote' => $branchLocked
                                ? 'Redeemable ONLY at the billing branch — ' . ($record->branch?->name ?? 'the issuing branch')
                                    . '. An OTP to the registered mobile is required to complete redemption.'
                                : 'Scan to redeem at any Lord Jeweller dealer. An OTP to the registered mobile is required to complete redemption.',
                        ]);
                    }),
                Tables\Actions\Action::make('whatsapp')
                    ->label('Send WhatsApp')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Bond $record) => (bool) $record->plan?->is_redeem)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Bond $record) => 'Send the contract PDF and redeemable QR to '
                        . optional($record->member)->name . ' (' . optional($record->member)->phone . ') via WhatsApp?')
                    ->action(function (Bond $record) {
                        if (! optional($record->member)->phone) {
                            \Filament\Notifications\Notification::make()->title('Distributor has no phone number')->warning()->send();

                            return;
                        }
                        $svc = app(\App\Services\Qr\RedeemableQrService::class);
                        $qr = $svc->forBond($record);
                        if (! $qr) {
                            \Filament\Notifications\Notification::make()
                                ->title('No QR minted yet')
                                ->body('This plan issues its single gold QR at the FIRST RENEWAL — collect a due first.')
                                ->warning()->send();

                            return;
                        }
                        $res = $svc->deliver($qr);

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
