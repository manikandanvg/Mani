<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\TaskTargetResource\Pages;
use App\Models\Branch;
use App\Models\Rank;
use App\Models\TaskTarget;
use App\Models\TaskType;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Tables\Actions;
use Filament\Tables\Table;

/**
 * Monthly Tasks → Task Targets: the rules. Employee tasks are set per TBP stage
 * (also editable on Network → TBP Stages → "Monthly tasks"), branch tasks per
 * branch level. The month roll turns every active rule into assignments.
 */
class TaskTargetResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = TaskTarget::class;

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Task Targets';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    /** Shared with the Ranks relation manager. */
    public static function formSchema(bool $lockRank = false): array
    {
        return [
            Forms\Components\Select::make('task_type_id')
                ->label('Task')
                ->options(fn (Forms\Get $get) => TaskType::where('is_active', true)
                    ->when($lockRank || $get('applies') === 'rank', fn ($q) => $q->where('scope', 'employee'))
                    ->when(! $lockRank && $get('applies') === 'level', fn ($q) => $q->where('scope', 'branch'))
                    ->orderBy('sort')->pluck('name', 'id'))
                ->required()->searchable()->preload()->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    if ($t = TaskType::find($state)) {
                        $set('target', (float) $t->default_target);
                        $set('per_day', $t->default_per_day !== null ? (float) $t->default_per_day : null);
                        $set('weight', (int) $t->default_weight);
                    }
                })
                ->columnSpan(2),
            Forms\Components\Radio::make('applies')
                ->label('Applies to')
                ->options(['rank' => 'Employees of a TBP stage', 'level' => 'Branches of a level'])
                ->default('rank')->live()->inline()
                ->hidden($lockRank)->dehydrated(false)
                ->afterStateHydrated(fn (Forms\Set $set, ?TaskTarget $record) => $set('applies', $record?->branch_level ? 'level' : 'rank'))
                ->columnSpan(2),
            Forms\Components\Select::make('rank_id')
                ->label('TBP stage')
                ->options(fn () => Rank::orderBy('depth')->get()->mapWithKeys(fn ($r) => [$r->id => Translatable::pick($r->name) ?: $r->code])->all())
                ->hidden(fn (Forms\Get $get) => $lockRank || $get('applies') !== 'rank')
                ->required(fn (Forms\Get $get) => ! $lockRank && $get('applies') === 'rank'),
            Forms\Components\Select::make('branch_level')
                ->label('Branch level')
                ->options(collect(Branch::LEVELS)->reject(fn ($l) => $l === 'hq')->mapWithKeys(fn ($l) => [$l => Branch::levelLabel($l)])->all())
                ->hidden(fn (Forms\Get $get) => $lockRank || $get('applies') !== 'level')
                ->required(fn (Forms\Get $get) => ! $lockRank && $get('applies') === 'level'),
            Forms\Components\TextInput::make('target')->numeric()->required()->minValue(0)
                ->helperText(fn (Forms\Get $get) => ($t = TaskType::find($get('task_type_id'))) ? (TaskType::UNITS[$t->unit] ?? '') . ($t->direction === 'down' ? ' — allowed maximum' : '') : null),
            Forms\Components\TextInput::make('per_day')->label('Per day')->numeric()->nullable()
                ->visible(fn (Forms\Get $get) => ($t = TaskType::find($get('task_type_id'))) && $t->key === 'OPEN_HOURS')
                ->helperText('Hours the branch must stay open for the day to count.'),
            Forms\Components\TextInput::make('weight')->numeric()->default(1)->minValue(0)->maxValue(10)
                ->helperText('Share of the month score; 0 = information only.'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([Forms\Components\Section::make()->columns(2)->schema(static::formSchema())]);
    }

    public static function tableColumns(bool $withApplies = true): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('taskType.name')->label('Task')->searchable()->wrap()
                ->description(fn (TaskTarget $r) => $r->taskType?->key),
            $withApplies ? Tables\Columns\TextColumn::make('applies_to')->label('Applies to')->state(fn (TaskTarget $r) => $r->appliesToLabel())
                ->badge()->color(fn (TaskTarget $r) => $r->rank_id ? 'primary' : 'warning') : null,
            Tables\Columns\TextColumn::make('target')->numeric(0)
                ->formatStateUsing(fn (TaskTarget $r) => $r->taskType?->format((float) $r->target) ?? $r->target)
                ->description(fn (TaskTarget $r) => $r->per_day ? rtrim(rtrim(number_format((float) $r->per_day, 1), '0'), '.') . ' h/day' : null),
            Tables\Columns\TextColumn::make('weight')->numeric(0)->alignCenter(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
        ]));
    }

    public static function table(Table $table): Table
    {
        TaskType::ensureDefaults();

        return $table
            ->columns(static::tableColumns())
            ->defaultSort('id')
            ->filters([
                Tables\Filters\SelectFilter::make('rank_id')->label('TBP stage')->relationship('rank', 'code'),
                Tables\Filters\SelectFilter::make('branch_level')->label('Branch level')
                    ->options(collect(Branch::LEVELS)->mapWithKeys(fn ($l) => [$l => Branch::levelLabel($l)])->all()),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskTargets::route('/'),
            'create' => Pages\CreateTaskTarget::route('/create'),
            'edit' => Pages\EditTaskTarget::route('/{record}/edit'),
        ];
    }
}
