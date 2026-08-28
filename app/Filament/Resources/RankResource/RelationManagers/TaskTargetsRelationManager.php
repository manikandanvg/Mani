<?php

namespace App\Filament\Resources\RankResource\RelationManagers;

use App\Filament\Resources\TaskTargetResource;
use App\Models\TaskType;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** TBP Stages → Monthly tasks: the employee task rules for this rank (board 2026-08-29). */
class TaskTargetsRelationManager extends RelationManager
{
    protected static string $relationship = 'taskTargets';

    protected static ?string $title = 'Monthly tasks';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema(TaskTargetResource::formSchema(lockRank: true));
    }

    public function table(Table $table): Table
    {
        TaskType::ensureDefaults();

        return $table
            ->columns(TaskTargetResource::tableColumns(withApplies: false))
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add task'),
                Tables\Actions\Action::make('add_all')
                    ->label('Add all employee tasks')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Adds every active employee task type with its default target (existing rows are kept).')
                    ->action(function () {
                        $rank = $this->getOwnerRecord();
                        TaskType::where('is_active', true)->where('scope', 'employee')->where('mode', 'auto')->orderBy('sort')->get()
                            ->each(fn (TaskType $t) => \App\Models\TaskTarget::firstOrCreate(
                                ['task_type_id' => $t->id, 'rank_id' => $rank->id, 'branch_level' => null],
                                ['target' => $t->default_target, 'per_day' => $t->default_per_day, 'weight' => $t->default_weight, 'is_active' => true],
                            ));
                    }),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([]);
    }
}
