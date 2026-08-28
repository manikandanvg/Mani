<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HiddenFromSupport;
use App\Filament\Resources\TaskAssignmentResource\Pages;
use App\Models\Branch;
use App\Models\Member;
use App\Models\TaskAssignment;
use App\Models\TaskType;
use App\Services\Tasks\TaskEngine;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Monthly Tasks → Monthly Progress: every member / branch × task for a month with
 * target, achieved, % and status. HQ can add one-off tasks, adjust a target and
 * see the proof behind manual tasks. Dealers see their own branch + themselves.
 */
class TaskAssignmentResource extends BaseResource
{
    use HiddenFromSupport;

    protected static ?string $model = TaskAssignment::class;

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Monthly Progress';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getModelLabel(): string
    {
        return __('Task');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Monthly Progress');
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with('taskType');
        $u = auth()->user();
        if ($u && $u->isDistributor()) {
            $memberId = Member::where('member_code', $u->member_code)->value('id');
            $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('subject_type', 'branch')->where('subject_id', (int) $u->branch_id))
                ->orWhere(fn ($x) => $x->where('subject_type', 'member')->where('subject_id', (int) $memberId)));
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('pct', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('month')->date('M Y')->sortable(),
                Tables\Columns\TextColumn::make('subject_type')->label('Who')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'branch' ? 'Branch' : 'Employee')
                    ->color(fn ($state) => $state === 'branch' ? 'warning' : 'primary'),
                Tables\Columns\TextColumn::make('subject_name')->label('Name')->state(fn (TaskAssignment $r) => $r->subjectName())->wrap()
                    ->searchable(query: function (Builder $q, string $search) {
                        $members = Member::where('member_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->pluck('id');
                        $branches = Branch::where('name', 'like', "%{$search}%")->pluck('id');
                        $q->where(fn ($w) => $w
                            ->where(fn ($x) => $x->where('subject_type', 'member')->whereIn('subject_id', $members))
                            ->orWhere(fn ($x) => $x->where('subject_type', 'branch')->whereIn('subject_id', $branches)));
                    }),
                Tables\Columns\TextColumn::make('task')->label('Task')->state(fn (TaskAssignment $r) => $r->title())->wrap()
                    ->description(fn (TaskAssignment $r) => $r->taskType?->key . ($r->source === 'manual' ? ' · added by HQ' : '')),
                Tables\Columns\TextColumn::make('target')->label('Target')
                    ->formatStateUsing(fn (TaskAssignment $r) => $r->taskType?->format((float) $r->target) . ($r->per_day ? ' × ' . rtrim(rtrim(number_format((float) $r->per_day, 1), '0'), '.') . ' h' : '')),
                Tables\Columns\TextColumn::make('achieved')->label('Achieved')
                    ->formatStateUsing(fn (TaskAssignment $r) => $r->taskType?->format((float) $r->achieved)),
                Tables\Columns\TextColumn::make('pct')->label('%')->numeric(0)->sortable()->alignRight()
                    ->color(fn ($state) => $state >= 100 ? 'success' : ($state >= 60 ? 'warning' : 'danger')),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => TaskAssignment::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'achieved' => 'success', 'on_track' => 'info', 'behind' => 'warning', 'missed' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('weight')->numeric(0)->alignCenter()->toggleable(),
                Tables\Columns\IconColumn::make('locked_at')->label('Locked')->boolean()->getStateUsing(fn (TaskAssignment $r) => $r->isLocked())->toggleable(),
                Tables\Columns\TextColumn::make('measured_at')->since()->label('Measured')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('month')
                    ->form([Forms\Components\Select::make('month')->options(static::monthOptions())->default(Carbon::now()->format('Y-m'))->native(false)])
                    ->query(fn (Builder $q, array $data) => $q->when($data['month'] ?? null,
                        fn ($qq, $m) => $qq->whereDate('month', Carbon::createFromFormat('Y-m', $m)->startOfMonth()->toDateString())))
                    ->indicateUsing(fn (array $data) => ($data['month'] ?? null) ? 'Month: ' . Carbon::createFromFormat('Y-m', $data['month'])->format('M Y') : null),
                Tables\Filters\SelectFilter::make('subject_type')->label('Who')->options(['member' => 'Employees', 'branch' => 'Branches']),
                Tables\Filters\SelectFilter::make('task_type_id')->label('Task')->relationship('taskType', 'name'),
                Tables\Filters\SelectFilter::make('status')->options(TaskAssignment::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth('2xl')
                    ->infolist([
                        \Filament\Infolists\Components\TextEntry::make('subject')->label('Who')->state(fn (TaskAssignment $r) => $r->subjectName()),
                        \Filament\Infolists\Components\TextEntry::make('task')->state(fn (TaskAssignment $r) => $r->title()),
                        \Filament\Infolists\Components\TextEntry::make('taskType.description')->label('How it is measured')->columnSpanFull(),
                        \Filament\Infolists\Components\TextEntry::make('detail')->label('Breakdown')->columnSpanFull()
                            ->state(fn (TaskAssignment $r) => collect($r->detail ?? [])->map(fn ($v, $k) => str_replace('_', ' ', $k) . ': ' . (is_array($v) ? json_encode($v) : $v))->implode(' · ') ?: '—'),
                        \Filament\Infolists\Components\TextEntry::make('note')->placeholder('—'),
                        \Filament\Infolists\Components\RepeatableEntry::make('submissions')->label('Proof submitted')->columnSpanFull()
                            ->visible(fn (TaskAssignment $r) => $r->taskType?->isManual())
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('created_at')->dateTime('d M Y H:i')->label('When'),
                                \Filament\Infolists\Components\TextEntry::make('status')->badge(),
                                \Filament\Infolists\Components\TextEntry::make('body')->label('Note')->placeholder('—')->columnSpanFull(),
                                \Filament\Infolists\Components\TextEntry::make('photo_path')->label('Photo')
                                    ->formatStateUsing(fn ($state, $record) => $state ? 'Open photo' : '—')
                                    ->url(fn ($record) => $record->photoUrl(), shouldOpenInNewTab: true),
                            ])->columns(2),
                    ]),
                Tables\Actions\Action::make('adjust')
                    ->label('Adjust')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (TaskAssignment $r) => ! auth()->user()?->isDistributor() && ! $r->isLocked())
                    ->form([
                        Forms\Components\TextInput::make('target')->numeric()->required()->default(fn (TaskAssignment $r) => (float) $r->target),
                        Forms\Components\TextInput::make('weight')->numeric()->required()->minValue(0)->maxValue(10)->default(fn (TaskAssignment $r) => $r->weight),
                        Forms\Components\TextInput::make('note')->maxLength(255)->default(fn (TaskAssignment $r) => $r->note),
                    ])
                    ->action(function (TaskAssignment $r, array $data) {
                        $r->update(['target' => $data['target'], 'weight' => $data['weight'], 'note' => $data['note'] ?? null]);
                        app(TaskEngine::class)->measureAssignment($r->fresh('taskType'));
                        Notification::make()->success()->title('Target adjusted')->send();
                    }),
                Tables\Actions\Action::make('remeasure')
                    ->label('Measure now')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (TaskAssignment $r) => ! $r->isLocked())
                    ->action(function (TaskAssignment $r) {
                        app(TaskEngine::class)->measureAssignment($r->fresh('taskType'));
                        Notification::make()->success()->title('Measured')->body($r->fresh()->pct . '% — ' . TaskAssignment::STATUS_LABELS[$r->fresh()->status])->send();
                    }),
                Tables\Actions\DeleteAction::make()->visible(fn (TaskAssignment $r) => ! auth()->user()?->isDistributor() && $r->source === 'manual' && ! $r->isLocked()),
            ])
            ->bulkActions([]);
    }

    public static function monthOptions(): array
    {
        $out = [];
        for ($i = -1; $i <= 6; $i++) {
            $m = Carbon::now()->subMonths($i)->startOfMonth();
            $out[$m->format('Y-m')] = $m->format('F Y');
        }

        return $out;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskAssignments::route('/'),
        ];
    }
}
