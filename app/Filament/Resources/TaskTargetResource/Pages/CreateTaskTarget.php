<?php

namespace App\Filament\Resources\TaskTargetResource\Pages;

use App\Filament\Resources\TaskTargetResource;
use App\Models\Branch;
use App\Models\Rank;
use App\Models\TaskTarget;
use App\Models\TaskType;
use App\Services\Tasks\TaskEngine;
use App\Support\Translatable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Smart" Task Targets screen (user 2026-08-29): three steps that unfold one after another —
 *   1. Applies to: employees of a TBP stage / branches of a level
 *   2. Pick the stage or level
 *   3. Tick the tasks and type each target (defaults pre-filled)
 * Saving writes one rule per ticked task (existing rules for that pair are updated), and
 * can push them into the current month at once (creates / refreshes assignments).
 */
class CreateTaskTarget extends CreateRecord
{
    protected static string $resource = TaskTargetResource::class;

    protected static ?string $title = 'Set task targets';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make([
                Forms\Components\Section::make('1 · Applies to')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Forms\Components\Radio::make('applies')
                            ->label('Who gets these tasks?')
                            ->options([
                                'rank' => 'Employees of a TBP stage — every active distributor at that stage',
                                'level' => 'Branches of a level — every active branch at that level',
                            ])
                            ->default('rank')->required()->live(),
                    ]),
                Forms\Components\Section::make('2 · Stage / level')
                    ->visible(fn (Forms\Get $get) => filled($get('applies')))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Forms\Components\Select::make('rank_id')
                            ->label('TBP stage')
                            ->options(fn () => Rank::orderBy('depth')->get()->mapWithKeys(fn ($r) => [$r->id => (Translatable::pick($r->name) ?: $r->code) . ' · ' . \App\Models\Member::where('status', 'active')->where('rank_id', $r->id)->count() . ' active'])->all())
                            ->visible(fn (Forms\Get $get) => $get('applies') === 'rank')
                            ->required(fn (Forms\Get $get) => $get('applies') === 'rank')
                            ->live()->native(false)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::prefill($get, $set)),
                        Forms\Components\Select::make('branch_level')
                            ->label('Branch level')
                            ->options(fn () => collect(Branch::LEVELS)->reject(fn ($l) => $l === 'hq')
                                ->mapWithKeys(fn ($l) => [$l => Branch::levelLabel($l) . ' · ' . Branch::where('is_active', true)->where('level', $l)->count() . ' active'])->all())
                            ->visible(fn (Forms\Get $get) => $get('applies') === 'level')
                            ->required(fn (Forms\Get $get) => $get('applies') === 'level')
                            ->live()->native(false)
                            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::prefill($get, $set)),
                        Forms\Components\Placeholder::make('existing')
                            ->label('Already set')
                            ->content(fn (Forms\Get $get) => ($n = static::existingRules($get)->count()) > 0
                                ? new \Illuminate\Support\HtmlString("{$n} rule(s) exist for this choice — they are pre-ticked in the next step and will be updated.")
                                : 'No rules yet for this choice.'),
                    ]),
                ...static::taskSections(),
            ])
                ->columnSpanFull(),
        ]);
    }

    /** Task types that fit the chosen audience. @return TaskType[] */
    protected static function typesFor(Forms\Get $get): array
    {
        $scope = $get('applies') === 'level' ? 'branch' : 'employee';

        return TaskType::where('is_active', true)->where('scope', $scope)->where('key', '!=', 'CUSTOM')->orderBy('sort')->get()->all();
    }

    protected static function existingRules(Forms\Get $get)
    {
        return TaskTarget::query()
            ->when($get('applies') === 'level', fn ($q) => $q->where('branch_level', $get('branch_level') ?: '__none__'),
                fn ($q) => $q->where('rank_id', $get('rank_id') ?: 0))
            ->get()->keyBy('task_type_id');
    }

    /** Stage / level chosen → tick the tasks that already have a rule and load their figures. */
    public static function prefill(Forms\Get $get, Forms\Set $set): void
    {
        $existing = static::existingRules($get);
        foreach (TaskType::where('is_active', true)->where('key', '!=', 'CUSTOM')->get() as $t) {
            $rule = $existing->get($t->id);
            $set("tasks.{$t->id}.on", $rule !== null);
            $set("tasks.{$t->id}.target", $rule ? (float) $rule->target : (float) $t->default_target);
            $set("tasks.{$t->id}.per_day", $rule ? ($rule->per_day !== null ? (float) $rule->per_day : null) : ($t->default_per_day !== null ? (float) $t->default_per_day : null));
            $set("tasks.{$t->id}.weight", $rule ? (int) $rule->weight : (int) $t->default_weight);
        }
    }

    /**
     * Step 3 as TWO sections (employee tasks / branch tasks); the one matching "Applies to"
     * shows once a stage / level is chosen. Visibility sits on the Section — Filament
     * evaluates a Grid's visible() before the Grid has a container, which breaks.
     */
    protected static function taskSections(): array
    {
        $sections = [];
        foreach (['employee' => 'rank', 'branch' => 'level'] as $scope => $applies) {
            $rows = [];
            foreach (TaskType::where('is_active', true)->where('scope', $scope)->where('key', '!=', 'CUSTOM')->orderBy('sort')->get() as $t) {
                $rows[] = Forms\Components\Grid::make(12)->schema([
                    Forms\Components\Checkbox::make("tasks.{$t->id}.on")
                        ->label($t->name)
                        ->helperText($t->description)
                        ->default(false)
                        ->columnSpan(6),
                    Forms\Components\TextInput::make("tasks.{$t->id}.target")
                        ->label($t->direction === 'down' ? 'Allowed max' : 'Target')
                        ->suffix(TaskType::UNITS[$t->unit] ?? $t->unit)
                        ->numeric()->minValue(0)
                        ->default((float) $t->default_target)
                        ->columnSpan(3),
                    Forms\Components\TextInput::make("tasks.{$t->id}.per_day")
                        ->label('Hours/day')->numeric()->minValue(1)->maxValue(24)
                        ->default($t->default_per_day !== null ? (float) $t->default_per_day : null)
                        ->visible($t->key === 'OPEN_HOURS')
                        ->columnSpan(1),
                    Forms\Components\TextInput::make("tasks.{$t->id}.weight")
                        ->label('Weight')->numeric()->minValue(0)->maxValue(10)
                        ->default((int) $t->default_weight)
                        ->columnSpan($t->key === 'OPEN_HOURS' ? 2 : 3),
                ]);
            }
            $rows[] = Forms\Components\Toggle::make("apply_now_{$scope}")
                ->label('Apply to the current month now')
                ->helperText("Also creates / refreshes this month's assignments for everyone matching. Leave off to start from next month's roll.")
                ->default(false);

            $sections[] = Forms\Components\Section::make('3 · Tasks & targets')
                ->description('Tick the tasks that must be completed every month and set each target. Defaults come from Task Types.')
                ->visible(fn (Forms\Get $get) => $get('applies') === $applies
                    && ($applies === 'level' ? filled($get('branch_level')) : filled($get('rank_id'))))
                ->schema($rows);
        }

        return $sections;
    }

    /** Write one rule per ticked task; returns the last one (the page needs a record). */
    protected function handleRecordCreation(array $data): Model
    {
        $isLevel = ($data['applies'] ?? 'rank') === 'level';
        $rankId = $isLevel ? null : (int) $data['rank_id'];
        $level = $isLevel ? (string) $data['branch_level'] : null;
        $engine = app(TaskEngine::class);
        $saved = 0; $applied = 0; $last = null;

        $ticked = collect($data['tasks'] ?? [])->filter(fn ($row) => ! empty($row['on']));
        if ($ticked->isEmpty()) {
            Notification::make()->warning()->title('Tick at least one task')->send();
            throw new \Filament\Support\Exceptions\Halt();
        }

        foreach ($data['tasks'] ?? [] as $typeId => $row) {
            if (empty($row['on'])) {
                continue;
            }
            $type = TaskType::find($typeId);
            if (! $type) {
                continue;
            }
            $last = TaskTarget::updateOrCreate(
                ['task_type_id' => $type->id, 'rank_id' => $rankId, 'branch_level' => $level],
                ['target' => (float) ($row['target'] ?? $type->default_target), 'per_day' => isset($row['per_day']) && $row['per_day'] !== '' && $row['per_day'] !== null ? (float) $row['per_day'] : null,
                    'weight' => (int) ($row['weight'] ?? $type->default_weight), 'is_active' => true],
            );
            $saved++;
            if (! empty($data['apply_now_employee']) || ! empty($data['apply_now_branch'])) {
                $applied += $engine->syncRule($last, Carbon::now(), auth()->id());
            }
        }

        Notification::make()->success()
            ->title("{$saved} target(s) saved")
            ->body((! empty($data['apply_now_employee']) || ! empty($data['apply_now_branch'])) ? "{$applied} assignment(s) created or refreshed for " . Carbon::now()->format('F Y') . '.' : 'They take effect at the next month roll (or use Monthly Progress → Roll month).')
            ->send();

        return $last ?? new TaskTarget();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
