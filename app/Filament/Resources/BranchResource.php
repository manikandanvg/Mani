<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Resources\BranchResource\RelationManagers;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BranchResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Branch::class;

    protected static ?string $navigationGroup = 'Master';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Branch details')->columns(2)->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(200),
                    // Board resolution 2026-10-24: dealers convert into Lord's Branches (the
                    // dealer becomes the branch in-charge); some stay independent dealers.
                    // Scheme availability (Scheme → Availability) keys off this.
                    Forms\Components\Radio::make('kind')
                        ->label('Kind')
                        ->options(Branch::KIND_LABELS)
                        ->default(Branch::KIND_DEALER)->required()->inline(false)->live()
                        ->disabled(fn (?Branch $record) => $record?->level === 'hq')
                        ->dehydrated()
                        ->helperText(fn (\Filament\Forms\Get $get) => $get('kind') === Branch::KIND_BRANCH
                            ? "A Lord's Branch: company stock, company billing; the former dealer is recorded as the in-charge below and, by TBP stage, goes onto the payroll as an employee."
                            : 'An independent dealership trading on its own account.')
                        ->afterStateUpdated(function (?string $state, \Filament\Forms\Set $set, \Filament\Forms\Get $get, ?Branch $record) {
                            // Converting to a Lord's Branch: pre-fill the in-charge from the dealer login.
                            if ($state === Branch::KIND_BRANCH && blank($get('incharge')) && $record) {
                                $name = $record->distributorUser()->value('name');
                                if ($name) {
                                    $set('incharge', $name);
                                }
                            }
                        })
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('incharge')->label('In-charge')->maxLength(200)
                        ->helperText(fn (\Filament\Forms\Get $get) => $get('kind') === Branch::KIND_BRANCH ? 'Branch in-charge (the converted dealer).' : null),
                    // Dealership ladder (board 2026-08-26) in Plans `hid` order; set
                    // automatically when a dealership plan is billed, editable here.
                    Forms\Components\Select::make('level')
                        ->label('Dealership level')
                        ->options(collect(Branch::LEVELS)->mapWithKeys(fn ($l, $i) => [$l => $i . ' · ' . Branch::levelLabel($l)])->all())
                        ->native(false)->live(),
                    Forms\Components\Select::make('source_branch_id')
                        ->label('Orders stock from (source)')
                        ->helperText(fn (\Filament\Forms\Get $get) => $get('level') && $get('level') !== 'hq'
                            ? 'Allowed for this level: ' . implode(', ', array_map(fn ($l) => Branch::levelLabel($l), Branch::allowedSourceLevels($get('level'))))
                            : 'HQ has no source.')
                        ->options(function (?Branch $record, \Filament\Forms\Get $get) {
                            $probe = $record ? clone $record : new Branch;
                            $probe->level = $get('level');

                            return $probe->sourceCandidates(hqOverride: true)->pluck('name', 'id')->all();
                        })
                        ->searchable(),
                    Forms\Components\TextInput::make('phone')->tel()->maxLength(20),
                    Forms\Components\TextInput::make('gst_no')->label('GST No')->maxLength(25),
                    Forms\Components\TextInput::make('invoice_prefix')->label('Invoice prefix')
                        ->helperText('This branch\'s billing series, e.g. HQ → INV-HQ-0001. Keep short & unique.')
                        ->maxLength(10)->alphaNum()
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('address')->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('city')->maxLength(120),
                    Forms\Components\TextInput::make('pincode')->maxLength(12),
                    Forms\Components\TextInput::make('country')->required()->maxLength(2)->default('IN'),
                    Forms\Components\TextInput::make('order_limit')->numeric(),
                    Forms\Components\Toggle::make('is_active')->default(true),
                    Forms\Components\TextInput::make('rfid_tag')->label('Branch RFID card')
                        ->maxLength(32)->unique(ignoreRecord: true)
                        ->helperText('The branch\'s own attendance card: its morning tap on the L-BOX opens the branch, the evening tap closes it. Tap an unregistered card on the box to read its number off the screen. Card lost? Just replace the number here.')
                        // Normalize BEFORE validation (unique compares the live state);
                        // dehydrate stays as the belt for programmatic fills.
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('rfid_tag', $state ? strtoupper(trim($state)) : null))
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            // A branch card must never collide with an EMPLOYEE card —
                            // the box checks the branch card first and would swallow
                            // that employee's attendance taps as open/close events.
                            $uid = strtoupper(trim((string) $value));
                            if ($uid !== '' && \App\Models\EmployeeProfile::where('rfid_tag', $uid)->exists()) {
                                $fail('This card is already registered as an EMPLOYEE attendance card.');
                            }
                        })
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null),
                ]),
                Forms\Components\Section::make('Currency & tax')->columns(3)
                    ->description('Operating currency and tax regime for this branch. India branches use INR + GST; a foreign branch (e.g. London) uses its own currency and VAT or none.')
                    ->schema([
                        Forms\Components\Select::make('currency_code')
                            ->label('Operating currency')
                            ->options(fn () => \App\Models\Currency::orderBy('code')->pluck('code', 'code'))
                            ->default('INR')->required()->native(false)->searchable(),
                        Forms\Components\Select::make('tax_regime')
                            ->label('Tax regime')
                            ->options(['gst' => 'GST (India — CGST + SGST)', 'vat' => 'VAT (single rate)', 'none' => 'None / exempt'])
                            ->default('gst')->required()->native(false)->live(),
                        Forms\Components\TextInput::make('vat_pct')
                            ->label('VAT %')->numeric()->default(0)
                            ->visible(fn (\Filament\Forms\Get $get) => $get('tax_regime') === 'vat'),
                    ]),
                Forms\Components\Section::make('Store locator (map pin)')->columns(2)->schema([
                    Forms\Components\TextInput::make('latitude')->numeric()->step('0.0000001')
                        ->placeholder('e.g. 11.0168'),
                    Forms\Components\TextInput::make('longitude')->numeric()->step('0.0000001')
                        ->placeholder('e.g. 76.9558'),
                ]),
                // Launch without L-BOX (board 2026-10-31, common 3): each branch has its own
                // STATIC payment QR; the app shows it so distributors scan and pay the branch.
                Forms\Components\Section::make('Payment QR')
                    ->description('The branch payment QR is its L-BOX static QR (Static QR button on the branch list, scanned in the app under Wallet → Withdraw at branch). By level it is not offered to resellers, area dealers and sub dealers — the switch below overrides that for this branch. The UPI fields are optional extras for the store card.')
                    ->columns(2)->schema([
                        // user 2026-11-03: a reseller / area dealer that asked for the L-BOX QR gets it here
                        Forms\Components\Select::make('static_qr')->label('Static QR (L-BOX QR)')
                            ->options(Branch::STATIC_QR_OPTIONS)
                            ->placeholder('Default by level (not for resellers, area dealers, sub dealers)')
                            ->native(false)
                            ->helperText('Enabled: the Static QR button, the dealer\'s My Branch QR page and the app store card show this branch\'s QR whatever its level. Disabled hides it even for a level that normally has one.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('upi_id')->label('UPI ID')->placeholder('branchname@upi')->maxLength(80),
                        Forms\Components\FileUpload::make('payment_qr_path')->label('QR image (optional)')
                            ->image()->directory('branch-qr')->maxSize(4096),
                    ]),
                Forms\Components\Section::make('Earned dealer margin balances (₹)')
                    ->description('Accrued automatically by sales/redemption; gold & silver GM kept separate.')
                    ->columns(2)->schema([
                        Forms\Components\TextInput::make('bill_margin')->label('Billing margin')->numeric()->default(0)->prefix('₹'),
                        Forms\Components\TextInput::make('stock_trans_margin')->label('Stock transfer margin')->numeric()->default(0)->prefix('₹'),
                        Forms\Components\TextInput::make('gold_gm_margin')->label('Gold GM margin')->numeric()->default(0)->prefix('₹'),
                        Forms\Components\TextInput::make('silver_gm_margin')->label('Silver GM margin')->numeric()->default(0)->prefix('₹'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Kind')->badge()
                    ->formatStateUsing(fn (?string $state, Branch $record) => $record->isLordBranch() ? "Lord's Branch" : 'Dealer')
                    ->color(fn (?string $state, Branch $record) => $record->isLordBranch() ? 'info' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('level')
                    ->formatStateUsing(fn ($state) => Branch::levelLabel($state))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? str($state)->replace('_', ' ')->title() : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sourceBranch.name')
                    ->label('Source')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pincode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('incharge')
                    ->searchable(),
                Tables\Columns\TextColumn::make('legacy_brid')
                    ->label('Legacy id')->sortable()->searchable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('gst_no')
                    ->searchable(),
                Tables\Columns\TextColumn::make('order_limit')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Pincode drives the network hierarchy (board 2026-10-31, common 1): list the
                // branches that still lack one — the Export toolbar then gives the Excel file.
                Tables\Filters\Filter::make('missing_pincode')
                    ->label('Missing pincode')
                    ->toggle()
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where(fn ($w) => $w->whereNull('pincode')->orWhere('pincode', ''))),
                Tables\Filters\SelectFilter::make('static_qr')->label('Static QR')
                    ->options(['on' => 'Enabled (override)', 'off' => 'Disabled (override)', 'level' => 'Default by level'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'on', 'off' => $query->where('static_qr', $data['value']),
                        'level' => $query->whereNull('static_qr'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('kind')->label('Kind')
                    ->options(['branch' => "Lord's Branches", 'dealer' => 'Dealers']),
                Tables\Filters\SelectFilter::make('level')->label('Dealership level')
                    ->options(collect(Branch::LEVELS)->mapWithKeys(fn ($l) => [$l => Branch::levelLabel($l)])->all()),
            ])
            ->actions([
                // "View as Dealer" — step into this branch's distributor session (super-admin only).
                Tables\Actions\Action::make('viewAsDealer')
                    ->label('View as Dealer')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->tooltip('Open the back-office as this branch\'s distributor')
                    ->visible(fn (Branch $record): bool => auth()->user()?->isSuperAdmin()
                        && $record->distributorUser()->exists())
                    ->url(fn (Branch $record): string => route('impersonate.start', $record->distributorUser->getKey()))
                    ->openUrlInNewTab(),
                // Board 2026-10-31: branch payment QR = L-BOX static QR, generated for dealers
                // (no box needed) — print it at the counter; the app scans it for payments.
                Tables\Actions\Action::make('staticQr')
                    ->label('Static QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    // board 2026-11-02: area dealers / sub dealers have no payment QR, for HQ too
                    ->visible(fn (Branch $record) => $record->showsPaymentQr()
                        && (! auth()->user()?->isDistributor() || (int) auth()->user()->branch_id === (int) $record->id))
                    ->modalHeading(fn (Branch $record) => __('Static payment QR') . ' — ' . $record->name)
                    ->modalContent(function (Branch $record) {
                        $device = $record->ensureStaticQr();

                        return view('filament.modals.device-static-qr', [
                            'device' => $device,
                            'qrUrl' => app(\App\Services\Qr\QrCodeService::class)->store($device->uuid, "lbox-{$device->serial_no}"),
                        ]);
                    })
                    ->modalWidth('md')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
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
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
