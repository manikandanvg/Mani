<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;

use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\RankResource\Pages;
use App\Filament\Resources\RankResource\RelationManagers;
use App\Models\Rank;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RankResource extends BaseResource
{
    use HqOnly;
    protected static ?string $model = Rank::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    // Auditor terminology (2026-07): Reward & Award ranks are "Turnover-based
    // Promotion (TBP) Stages".
    protected static ?string $modelLabel = 'TBP Stage';

    protected static ?string $pluralModelLabel = 'TBP Stages';

    protected static ?string $navigationLabel = 'TBP Stages';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(40),
                Translatable::fieldset('name', 'Name'),
                Forms\Components\TextInput::make('depth')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('target_bv')
                    ->label('Target Business Volume')
                    ->required()
                    ->numeric()
                    ->default(0.00),
                // Board 2026-11-17: the award of the stage in words (old system: "Reward & Award" —
                // car, tour, international tour …), its rupee value, and the monthly office rent
                // the stage is entitled to. Paying one is registered under Report → Expenses.
                Translatable::fieldset('reward_title', 'TBP Award', requiredDefault: false),
                Forms\Components\TextInput::make('reward_amount')
                    ->label('TBP Reward Amount')
                    ->helperText('Rupee value of the award above — what the company spends when a distributor reaches this stage (one time). Not credited to any wallet; it is the budget the Expenses page fills in for a "TBP Reward / Award" expense.')
                    ->required()
                    ->numeric()
                    ->prefix('₹')
                    ->default(0.00),
                Forms\Components\TextInput::make('monthly_rent')
                    ->label('Monthly Office Rent')
                    ->helperText('Office rent the company pays every month for a distributor holding this stage. Filled in on the Expenses page for an "Office Rent" expense.')
                    ->required()
                    ->numeric()
                    ->prefix('₹')
                    ->default(0.00),
                Forms\Components\TagsInput::make('tier_template')
                    ->helperText('Top-N downline Gross Business Volume thresholds for Turnover-based Salary qualification'),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Translatable::column('name', 'Name'),
                Tables\Columns\TextColumn::make('depth')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_bv')
                    ->label('Target Business Volume')
                    ->numeric()
                    ->sortable(),
                Translatable::column('reward_title', 'TBP Award')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('reward_amount')
                    ->label('TBP Reward Amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('monthly_rent')
                    ->label('Monthly Office Rent')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort')
                    ->numeric()
                    ->sortable(),
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
            // Board 2026-08-29: monthly tasks are set per TBP stage right here.
            RelationManagers\TaskTargetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRanks::route('/'),
            'create' => Pages\CreateRank::route('/create'),
            'edit' => Pages\EditRank::route('/{record}/edit'),
        ];
    }
}
