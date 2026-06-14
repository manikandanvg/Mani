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

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Translatable::fieldset('name', 'Name'),
                Forms\Components\Section::make('Plan')->columns(3)->schema([
                    Forms\Components\TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true),
                    Forms\Components\Select::make('plan_type')
                        ->label('Plan type')
                        ->options([1 => 'RD', 2 => 'Digital (Cash/Gold/Silver)', 3 => 'Gold', 4 => 'Silver'])
                        ->default(1)->required(),
                    Forms\Components\Select::make('type')
                        ->label('Settlement')
                        ->options(['rd' => 'RD', 'digital' => 'Digital', 'gold' => 'Gold', 'silver' => 'Silver', 'others' => 'Others'])
                        ->default('rd')->required(),
                    Forms\Components\TextInput::make('min_value')->label('Min value')->required()->numeric(),
                    Forms\Components\TextInput::make('allocation_pct')->label('Allocation %')->required()->numeric()->default(100),
                    Forms\Components\TextInput::make('validity_months')->required()->numeric()->default(12),
                ]),
                Forms\Components\Section::make('Cashback (CBC)')->columns(2)->schema([
                    Forms\Components\TextInput::make('cbc_value')->label('Cashback %')->numeric()->default(0),
                    Forms\Components\TextInput::make('cbc_count')->label('Cashback months')->numeric()->default(0),
                ]),
                Forms\Components\Section::make('Commissions')->columns(2)->schema([
                    Forms\Components\TagsInput::make('ic_schedule')
                        ->label('IC schedule (10 levels)')
                        ->helperText('Instant-commission % per level, e.g. 10, 3, 2, 1.5, 0.75, ...'),
                    Forms\Components\TagsInput::make('level_schedule')
                        ->label('Level/GAP schedule')
                        ->helperText('Level commission % per depth, e.g. 1.25, 0.5, 0.25, ...'),
                    Forms\Components\TextInput::make('level_depth')->label('Level depth')->numeric()->default(0),
                    Forms\Components\TextInput::make('level_com_duration')->label('Level com duration (months)')->numeric()->default(0),
                ]),
                Forms\Components\Section::make('Branch margins (%)')->columns(3)->schema([
                    Forms\Components\TextInput::make('billing_margin')->label('Billing margin')->numeric()->default(0),
                    Forms\Components\TextInput::make('gm_margin')->label('Redemption (GM) margin')->numeric()->default(0),
                    Forms\Components\TextInput::make('stock_trans_margin')->label('Stock transfer margin')->numeric()->default(0),
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('min_value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('allocation_pct')
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
