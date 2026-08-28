<?php

namespace App\Filament\Resources;

use App\Filament\BaseResource;
use App\Filament\Concerns\HiddenFromSupport;
use App\Filament\Resources\TaskScoreResource\Pages;
use App\Models\Member;
use App\Models\TaskScore;
use App\Services\Tasks\TaskEngine;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Monthly Tasks → Scorecard: the month score per employee / branch. Locked on the
 * 1st; HQ may override with a note. Employee scores scale GAP + payroll (CBC exempt).
 */
class TaskScoreResource extends BaseResource
{
    use HiddenFromSupport;

    protected static ?string $model = TaskScore::class;

    protected static ?string $navigationGroup = 'Monthly Tasks';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Scorecard';

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    public static function getModelLabel(): string
    {
        return __('Score');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Scorecard');
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();
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
            ->defaultSort('month', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('month')->date('M Y')->sortable(),
                Tables\Columns\TextColumn::make('subject_type')->label('Who')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'branch' ? 'Branch' : 'Employee')
                    ->color(fn ($state) => $state === 'branch' ? 'warning' : 'primary'),
                Tables\Columns\TextColumn::make('subject_name')->label('Name')->state(fn (TaskScore $r) => $r->subjectName())->wrap(),
                Tables\Columns\TextColumn::make('score_pct')->label('Score %')->numeric(0)->sortable()->alignRight()
                    ->color(fn ($state) => $state >= 100 ? 'success' : ($state >= 75 ? 'info' : ($state >= 50 ? 'warning' : 'danger'))),
                Tables\Columns\TextColumn::make('adjusted_pct')->label('Adjusted %')->numeric(0)->placeholder('—')->alignRight()
                    ->description(fn (TaskScore $r) => $r->adjust_note),
                Tables\Columns\TextColumn::make('rating')->label('Rating')->badge()
                    ->state(fn (TaskScore $r) => TaskEngine::scoreLabel($r->effectivePct()))
                    ->color(fn ($state) => match ($state) { 'Achieved' => 'success', 'On track' => 'info', 'Behind' => 'warning', default => 'danger' }),
                Tables\Columns\TextColumn::make('tasks')->label('Tasks')->state(fn (TaskScore $r) => "{$r->tasks_achieved} / {$r->tasks_total}")->alignCenter(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'locked' ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('pay_factor')->label('Pay factor')->state(fn (TaskScore $r) => $r->subject_type === 'member' ? number_format($r->effectivePct() / 100, 2) . '×' : '—')
                    ->tooltip('Scales the month\'s turnover-based salary and payroll gross; CBC exempt'),
            ])
            ->filters([
                Tables\Filters\Filter::make('month')
                    ->form([Forms\Components\Select::make('month')->options(\App\Filament\Resources\TaskAssignmentResource::monthOptions())->native(false)])
                    ->query(fn (Builder $q, array $data) => $q->when($data['month'] ?? null,
                        fn ($qq, $m) => $qq->whereDate('month', Carbon::createFromFormat('Y-m', $m)->startOfMonth()->toDateString()))),
                Tables\Filters\SelectFilter::make('subject_type')->label('Who')->options(['member' => 'Employees', 'branch' => 'Branches']),
                Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'locked' => 'Locked']),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')
                    ->label('Adjust')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() || (auth()->user() && ! auth()->user()->isDistributor()))
                    ->form([
                        Forms\Components\TextInput::make('adjusted_pct')->label('Score % to apply')->numeric()->minValue(0)->maxValue(100)->required()
                            ->default(fn (TaskScore $r) => $r->effectivePct()),
                        Forms\Components\TextInput::make('adjust_note')->label('Reason')->required()->maxLength(255),
                    ])
                    ->action(function (TaskScore $r, array $data) {
                        $r->update(['adjusted_pct' => $data['adjusted_pct'], 'adjust_note' => $data['adjust_note'], 'adjusted_by' => auth()->id()]);
                        Notification::make()->success()->title('Score adjusted')->send();
                    }),
                Tables\Actions\Action::make('recalculate')
                    ->label('Recalculate')->icon('heroicon-o-arrow-path')
                    ->visible(fn (TaskScore $r) => $r->status !== 'locked' && ! auth()->user()?->isDistributor())
                    ->action(function (TaskScore $r) {
                        app(TaskEngine::class)->score($r->subject_type, (int) $r->subject_id, $r->month);
                        Notification::make()->success()->title('Recalculated')->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTaskScores::route('/')];
    }
}
