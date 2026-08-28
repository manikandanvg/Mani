<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HqOnly;
use App\Filament\Resources\TaskTypeResource\Pages;
use App\Models\TaskType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Monthly Tasks → Task Types: the catalogue (board 2026-08-29). Auto types are
 * code-backed (the engine knows how to measure them) — HQ can rename, describe,
 * re-weight and switch them off. Manual types can be added freely.
 */
class TaskTypeResource extends BaseResource
{
    use HqOnly;

    protected static ?string $model = TaskType::class;

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Task Types';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('key')->required()->maxLength(40)->alphaDash()
                    ->disabled(fn (?TaskType $record) => $record && in_array($record->key, array_column(TaskType::DEFAULTS, 'key'), true))
                    ->dehydrated()->helperText('Code used by the engine; built-in keys cannot change.'),
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                Forms\Components\Textarea::make('description')->rows(2)->maxLength(255)->columnSpanFull(),
                Forms\Components\Select::make('scope')->options(TaskType::SCOPES)->required()->native(false),
                Forms\Components\Select::make('mode')->options(TaskType::MODES)->required()->native(false)
                    ->disabled(fn (?TaskType $record) => $record && in_array($record->key, array_column(TaskType::DEFAULTS, 'key'), true))
                    ->dehydrated()
                    ->helperText('New types are always "proof submitted" — the engine only measures the built-in keys.'),
                Forms\Components\Select::make('unit')->options(TaskType::UNITS)->required()->native(false),
                Forms\Components\Select::make('direction')->options(['up' => 'More is better', 'down' => 'Fewer is better'])->required()->native(false),
                Forms\Components\TextInput::make('default_target')->label('Default target')->numeric()->default(0),
                Forms\Components\TextInput::make('default_per_day')->label('Per day (e.g. hours)')->numeric()->nullable(),
                Forms\Components\TextInput::make('default_weight')->label('Weight')->numeric()->default(1)->minValue(0)->maxValue(10)
                    ->helperText('0 = shown for information, not scored.'),
                Forms\Components\TextInput::make('sort')->numeric()->default(100),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        TaskType::ensureDefaults();

        return $table
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('key')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable()->wrap()->description(fn (TaskType $r) => $r->description),
                Tables\Columns\TextColumn::make('scope')->badge()->formatStateUsing(fn ($state) => $state === 'branch' ? 'Branch' : 'Employee')
                    ->color(fn ($state) => $state === 'branch' ? 'warning' : 'primary'),
                Tables\Columns\TextColumn::make('mode')->badge()->formatStateUsing(fn ($state) => $state === 'auto' ? 'Auto' : 'Manual')
                    ->color(fn ($state) => $state === 'auto' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('unit')->formatStateUsing(fn ($state) => TaskType::UNITS[$state] ?? $state),
                Tables\Columns\TextColumn::make('direction')->label('Better')->formatStateUsing(fn ($state) => $state === 'down' ? 'Fewer' : 'More'),
                Tables\Columns\TextColumn::make('default_target')->label('Default')->numeric(0),
                Tables\Columns\TextColumn::make('default_weight')->label('Weight')->numeric(0)->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('scope')->options(TaskType::SCOPES),
                Tables\Filters\SelectFilter::make('mode')->options(TaskType::MODES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskTypes::route('/'),
            'create' => Pages\CreateTaskType::route('/create'),
            'edit' => Pages\EditTaskType::route('/{record}/edit'),
        ];
    }
}
