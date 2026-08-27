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
                    Forms\Components\TextInput::make('incharge')->label('In-charge')->maxLength(200),
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
                //
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
