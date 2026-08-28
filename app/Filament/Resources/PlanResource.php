<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Resources\PlanResource\RelationManagers;
use App\Models\Plan;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Plan::class;

    protected static ?string $navigationGroup = 'Master';

    // Auditor terminology (2026-07): "Plan" is displayed as "Scheme" everywhere.
    protected static ?string $navigationLabel = 'Schemes';

    protected static ?string $modelLabel = 'Scheme';

    protected static ?string $pluralModelLabel = 'Schemes';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translatable::fieldset('name', 'Name'),
                Forms\Components\Section::make('Scheme')->columns(3)->schema([
                    Forms\Components\TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('hid')
                        ->label('Hierarchy order')->numeric()
                        ->helperText('Dealership ladder position (1 = Regional); blank for non-hierarchy schemes'),
                    Forms\Components\Select::make('plan_type')
                        ->label('Scheme type')
                        ->options([1 => 'RD', 2 => 'Digital (Cash/Gold/Silver)', 3 => 'Gold', 4 => 'Silver'])
                        ->default(1)->required(),
                    Forms\Components\Select::make('type')
                        ->label('Settlement')
                        ->options(['rd' => 'RD', 'digital' => 'Digital', 'gold' => 'Gold', 'silver' => 'Silver', 'others' => 'Others'])
                        ->default('rd')->required(),
                    Forms\Components\TextInput::make('min_value')->label('Min value')->required()->numeric(),
                    Forms\Components\TextInput::make('max_value')->label('Max value')->numeric()
                        ->helperText('Per-bill ceiling; blank = no limit'),
                    Forms\Components\TextInput::make('max_sales_value')->label('Max sales / month')->numeric()
                        ->helperText('Reseller monthly billing cap; blank = no cap'),
                    Forms\Components\TextInput::make('allocation_bv')->label('Allocation BV %')->required()->numeric()->default(100),
                    Forms\Components\TextInput::make('allocation_cont')->label('Allocation contract %')->numeric()->default(0),
                    Forms\Components\TextInput::make('validity_months')->required()->numeric()->default(12),
                ]),
                Forms\Components\Section::make('Flags')->columns(4)->schema([
                    Forms\Components\Toggle::make('is_redeem')->label('Redeemable QR'),
                    Forms\Components\Toggle::make('is_contract')->label('Has contract'),
                    Forms\Components\Toggle::make('is_invoice')->label('Has invoice'),
                    Forms\Components\Toggle::make('useraccess')->label('User-access tick at redeem'),
                ]),
                Forms\Components\Section::make('Ranking BV')->columns(2)->schema([
                    Forms\Components\Toggle::make('counts_for_rank')->label('Counts for reward/rank BV')->default(true),
                    Forms\Components\TextInput::make('rank_factor')->label('Rank BV %')->numeric()->default(100)
                        ->helperText('% of billed value counted as pure/ranking BV'),
                ]),
                Forms\Components\Section::make('Promotional Incentive (CBC)')->columns(2)->schema([
                    Forms\Components\TextInput::make('cbc_value')->label('Promotional Incentive (CBC) %')->numeric()->default(0),
                    Forms\Components\TextInput::make('cbc_count')->label('Promotional Incentive (CBC) months')->numeric()->default(0),
                ]),
                Forms\Components\Section::make('Commissions')->columns(2)->schema([
                    Forms\Components\TagsInput::make('ic_schedule')
                        ->label('Sales Incentive (IC) schedule (10 placement layers)')
                        ->helperText('Sales Incentive % per placement layer, e.g. 10, 3, 2, 1.5, 0.75, ...'),
                    Forms\Components\TagsInput::make('level_schedule')
                        ->label('Turnover-based Salary (GAP) schedule')
                        ->helperText('Turnover-based Salary % per placement layer depth, e.g. 1.25, 0.5, 0.25, ...'),
                    Forms\Components\TextInput::make('level_depth')->label('Placement layer depth')->numeric()->default(0),
                    Forms\Components\TextInput::make('level_com_duration')->label('Turnover-based Salary duration (months)')->numeric()->default(0),
                ]),
                Forms\Components\Section::make('Branch margins (%)')->columns(2)->schema([
                    Forms\Components\TextInput::make('billing_margin')->label('Billing margin')->numeric()->default(0),
                    Forms\Components\TextInput::make('renewal_margin')->label('RD renewal margin')->numeric()->default(0),
                ]),
                Forms\Components\Section::make()->columns(3)->schema([
                    Forms\Components\TextInput::make('epin_count')->label('E-pin count')->numeric()->default(0),
                    Forms\Components\Select::make('mou_id')
                        ->label('MOU')
                        ->relationship('mou', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($record) => Translatable::pick($record->title) ?: "MOU #{$record->id}")
                        ->searchable(),
                    Forms\Components\Toggle::make('is_active')->label('Visible / billable')->default(true),
                ]),
                Forms\Components\Section::make('RD gold QR')->columns(3)->schema([
                    Forms\Components\TextInput::make('rd_qr_grams')->label('Gold weight (g)')->numeric()
                        ->helperText('QR + fixed renewal amount backed by this gold weight (e.g. 0.100 = 100 mg); blank = value-based QR'),
                    Forms\Components\Select::make('rd_qr_on')->label('QR minted')
                        ->options(['always' => 'At bill + every renewal', 'first_renewal' => 'Once, at first renewal'])
                        ->placeholder('Never'),
                    Forms\Components\Select::make('rd_qr_product_id')->label('QR product')
                        ->relationship('rdQrProduct', 'code')
                        ->helperText('Catalog product supplying making/wastage/GST %')
                        ->searchable(),
                ]),
                Forms\Components\Section::make('Settlement')->columns(3)->schema([
                    Forms\Components\TextInput::make('settlement_cycle_months')->label('Cycle (months)')->numeric()
                        ->helperText('Months from contract start to the settlement event; blank = never settles'),
                    Forms\Components\TextInput::make('settlement_qr_pct')->label('QR worth %')->numeric()
                        ->helperText('Settlement gold-QR worth as % of the contract amount'),
                    Forms\Components\TextInput::make('settlement_bonus_months')->label('RD bonus months')->numeric()->default(0)
                        ->helperText('G11 RD: QR = paid total + bonus months × monthly'),
                    Forms\Components\Toggle::make('settlement_close')->label('Close contract at settlement'),
                    Forms\Components\Toggle::make('settlement_suspend')->label('Suspend dealer login'),
                    Forms\Components\Textarea::make('settlement')->label('Settlement note')->rows(2)->maxLength(255),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hid')
                    ->label('Hier.')
                    ->numeric()
                    ->sortable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('min_value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('allocation_bv')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('validity_months')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cbc_value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cbc_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('epin_count')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mou_id')
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
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
