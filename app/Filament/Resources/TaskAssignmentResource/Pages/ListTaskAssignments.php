<?php

namespace App\Filament\Resources\TaskAssignmentResource\Pages;

use App\Filament\Resources\TaskAssignmentResource;
use App\Models\Branch;
use App\Models\Member;
use App\Models\TaskType;
use App\Services\Tasks\TaskEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListTaskAssignments extends ListRecords
{
    protected static string $resource = TaskAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        $hq = fn () => ! auth()->user()?->isDistributor();

        return [
            // One-off task for one member or branch (typically CUSTOM: "what HQ typed").
            Actions\Action::make('add_task')
                ->label('Add task')
                ->icon('heroicon-o-plus')
                ->visible($hq)
                ->form([
                    Forms\Components\Select::make('month')->options(TaskAssignmentResource::monthOptions())
                        ->default(Carbon::now()->format('Y-m'))->required()->native(false),
                    Forms\Components\Radio::make('subject_type')->label('For')->options(['member' => 'Employee', 'branch' => 'Branch'])
                        ->default('member')->inline()->live()->required(),
                    Forms\Components\Select::make('member_id')->label('Employee')
                        ->visible(fn (Forms\Get $get) => $get('subject_type') === 'member')
                        ->required(fn (Forms\Get $get) => $get('subject_type') === 'member')
                        ->getSearchResultsUsing(fn (string $search) => Member::where('member_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                            ->limit(25)->get()->mapWithKeys(fn ($m) => [$m->id => $m->member_code . ' — ' . $m->name])->all())
                        ->getOptionLabelUsing(fn ($value) => ($m = Member::find($value)) ? $m->member_code . ' — ' . $m->name : null)
                        ->searchable(),
                    Forms\Components\Select::make('branch_id')->label('Branch')
                        ->visible(fn (Forms\Get $get) => $get('subject_type') === 'branch')
                        ->required(fn (Forms\Get $get) => $get('subject_type') === 'branch')
                        ->options(fn () => Branch::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                    Forms\Components\Select::make('task_type_id')->label('Task type')
                        ->options(fn (Forms\Get $get) => TaskType::where('is_active', true)
                            ->where('scope', $get('subject_type') === 'branch' ? 'branch' : 'employee')->orderBy('sort')->pluck('name', 'id'))
                        ->default(fn () => TaskType::where('key', 'CUSTOM')->value('id'))
                        ->required()->live()->native(false),
                    Forms\Components\TextInput::make('title')->label('Task (what must be done)')->maxLength(200)
                        ->visible(fn (Forms\Get $get) => ($t = TaskType::find($get('task_type_id'))) && $t->key === 'CUSTOM')
                        ->required(fn (Forms\Get $get) => ($t = TaskType::find($get('task_type_id'))) && $t->key === 'CUSTOM'),
                    Forms\Components\TextInput::make('target')->numeric()->required()->default(1)->minValue(0),
                    Forms\Components\TextInput::make('weight')->numeric()->required()->default(1)->minValue(0)->maxValue(10),
                    Forms\Components\TextInput::make('note')->maxLength(255),
                ])
                ->action(function (array $data) {
                    $type = TaskType::findOrFail($data['task_type_id']);
                    $id = $data['subject_type'] === 'branch' ? (int) $data['branch_id'] : (int) $data['member_id'];
                    $row = app(TaskEngine::class)->assignManual(
                        $data['subject_type'], $id, $type, Carbon::createFromFormat('Y-m', $data['month']),
                        (float) $data['target'], (int) $data['weight'], $data['title'] ?? null, $data['note'] ?? null, auth()->id(),
                    );
                    app(TaskEngine::class)->measureAssignment($row->fresh('taskType'));
                    if ($data['subject_type'] === 'member' && ($m = Member::find($id))) {
                        \App\Services\Push\Notifier::to($m, 'system', 'New monthly task: ' . $row->title(),
                            'Target ' . $type->format((float) $data['target']) . ' for ' . $row->month->format('F Y') . '. See My Status → Monthly Tasks.',
                            route: '/business/tasks');
                    }
                    Notification::make()->success()->title('Task added')->send();
                }),
            Actions\Action::make('roll')
                ->label('Roll month')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible($hq)
                ->form([Forms\Components\Select::make('month')->options(TaskAssignmentResource::monthOptions())
                    ->default(Carbon::now()->format('Y-m'))->required()->native(false)])
                ->modalDescription('Creates the month\'s assignments from the Task Targets rules for every matching employee and branch. Existing rows are kept.')
                ->action(function (array $data) {
                    $engine = app(TaskEngine::class);
                    $month = Carbon::createFromFormat('Y-m', $data['month']);
                    $n = $engine->rollMonth($month, auth()->id());
                    $m = $engine->measure($month);
                    Notification::make()->success()->title("Rolled {$month->format('F Y')}")->body("{$n} assignment(s) created · {$m} measured.")->send();
                }),
            Actions\Action::make('measure')
                ->label('Measure now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible($hq)
                ->action(function () {
                    $n = app(TaskEngine::class)->measure();
                    Notification::make()->success()->title("Measured {$n} assignment(s)")->send();
                }),
        ];
    }
}
