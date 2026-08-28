<?php

namespace App\Services\Tasks;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\BranchAttendance;
use App\Models\BranchStockDay;
use App\Models\EmployeeVisit;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\MemberMonthSnapshot;
use App\Models\RdEntry;
use App\Models\SalesInvoice;
use App\Models\SocialPost;
use App\Models\Stock;
use App\Models\TaskAssignment;
use App\Models\TaskScore;
use App\Models\TaskTarget;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly Tasks Engine (board 2026-08-28 / answers 2026-08-29).
 *
 *  rollMonth      the 1st: every active rule (per TBP stage / per branch level) becomes one
 *                 assignment per matching member / branch; GBV baseline snapshot taken
 *  measure        nightly: fills achieved / % / status of every unlocked assignment from the
 *                 ledgers the system already keeps (no admin typing)
 *  autoClose      20:00: a branch that opened but never tapped close is closed at 8 PM, and the
 *                 stock snapshot (quantity vs Opening, per metal item) is taken
 *  lockMonth      the 1st: final achieved / missed, weighted score per member and branch, locked
 *
 *  Score → pay: TaskScore::factorFor() scales the member's GAP for that month and the payroll
 *  gross (CBC is exempt). No score row (no tasks assigned) = factor 1.
 */
class TaskEngine
{
    public const AUTO_CLOSE_HOUR = 20;
    public const ZOOM_VERIFIED_SHARE = 0.5;   // ≥ 50 % of the scheduled duration = verified join
    public const LBOX_MEETING_WINDOW_H = 2;   // arena tap accepted this many hours around the meeting
    public const DOWN_PENALTY_PER_UNIT = 10;  // "fewer is better" tasks lose 10 % per unit over target

    // ── Month roll ──────────────────────────────────────────────────────────────

    /** Create the month's assignments from the rules. Idempotent. Returns rows created. */
    public function rollMonth(?Carbon $month = null, ?int $userId = null): int
    {
        $month = self::monthOf($month);
        TaskType::ensureDefaults();
        $created = 0;

        $rules = TaskTarget::with('taskType')->where('is_active', true)
            ->whereHas('taskType', fn ($q) => $q->where('is_active', true))->get();

        // Employee tasks: every active member whose TBP stage has rules.
        $byRank = $rules->whereNotNull('rank_id')->groupBy('rank_id');
        foreach ($byRank as $rankId => $rankRules) {
            Member::where('status', 'active')->where('rank_id', $rankId)->select('id')
                ->chunkById(300, function ($members) use ($rankRules, $month, $userId, &$created) {
                    foreach ($members as $m) {
                        foreach ($rankRules as $rule) {
                            $created += $this->assign('member', $m->id, $rule, $month, $userId) ? 1 : 0;
                        }
                    }
                });
        }

        // Branch tasks: every active non-HQ branch whose level has rules.
        $byLevel = $rules->whereNotNull('branch_level')->groupBy('branch_level');
        foreach ($byLevel as $level => $levelRules) {
            Branch::where('is_active', true)->where('level', $level)->select('id')
                ->chunkById(300, function ($branches) use ($levelRules, $month, $userId, &$created) {
                    foreach ($branches as $b) {
                        foreach ($levelRules as $rule) {
                            $created += $this->assign('branch', $b->id, $rule, $month, $userId) ? 1 : 0;
                        }
                    }
                });
        }

        $this->snapshotMembers($month);

        return $created;
    }

    protected function assign(string $type, int $id, TaskTarget $rule, Carbon $month, ?int $userId): bool
    {
        // whereDate lookup: the month column is a date cast, a plain where() would miss
        // '2026-08-01 00:00:00' rows on SQLite and try to insert a duplicate.
        $exists = TaskAssignment::forMonth($month)->forSubject($type, $id)->where('task_type_id', $rule->task_type_id)->exists();
        if ($exists) {
            return false;
        }
        TaskAssignment::create([
            'month' => $month->toDateString(), 'subject_type' => $type, 'subject_id' => $id, 'task_type_id' => $rule->task_type_id,
            'target' => $rule->target, 'per_day' => $rule->per_day, 'weight' => $rule->weight, 'source' => 'rule', 'created_by' => $userId,
        ]);

        return true;
    }

    /** GBV / BV / directs baseline on the 1st (only members missing one for the month). */
    public function snapshotMembers(?Carbon $month = null): int
    {
        $month = self::monthOf($month);
        $n = 0;
        Member::where('status', 'active')
            ->whereDoesntHave('monthSnapshots', fn ($q) => $q->whereDate('month', $month->toDateString()))
            ->select(['id', 'gbv', 'bv'])
            ->chunkById(500, function ($members) use ($month, &$n) {
                foreach ($members as $m) {
                    MemberMonthSnapshot::create([
                        'member_id' => $m->id, 'month' => $month->toDateString(),
                        'gbv' => (float) $m->gbv, 'bv' => (float) $m->bv,
                        'direct_count' => Member::where('upline_id', $m->id)->count(),
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /** HQ adds a one-off task (typically CUSTOM) to one member or branch. */
    public function assignManual(string $type, int $id, TaskType $taskType, Carbon $month, float $target, int $weight, ?string $title, ?string $note, ?int $userId): TaskAssignment
    {
        $month = self::monthOf($month);

        $row = TaskAssignment::forMonth($month)->forSubject($type, $id)->where('task_type_id', $taskType->id)->first()
            ?? new TaskAssignment(['month' => $month->toDateString(), 'subject_type' => $type, 'subject_id' => $id, 'task_type_id' => $taskType->id]);
        $row->fill(['target' => $target, 'weight' => $weight, 'source' => 'manual', 'title' => $title, 'note' => $note, 'created_by' => $userId])->save();

        return $row;
    }

    // ── Measuring ───────────────────────────────────────────────────────────────

    /** Measure every unlocked assignment of the month. Returns rows measured. */
    public function measure(?Carbon $month = null): int
    {
        $month = self::monthOf($month);
        $n = 0;
        TaskAssignment::with('taskType')->forMonth($month)->whereNull('locked_at')
            ->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $a) {
                    $this->measureAssignment($a);
                    $n++;
                }
            });

        return $n;
    }

    public function measureAssignment(TaskAssignment $a, ?Carbon $now = null): TaskAssignment
    {
        $now ??= Carbon::now();
        $type = $a->taskType;
        [$achieved, $detail] = $this->achievedFor($a);

        $pct = $this->pct($type, (float) $a->target, $achieved);
        $a->forceFill([
            'achieved' => round($achieved, 2),
            'pct' => $pct,
            'detail' => $detail,
            'status' => $this->status($a, $pct, $now),
            'measured_at' => $now,
        ])->save();

        return $a;
    }

    /** @return array{0: float, 1: array} */
    protected function achievedFor(TaskAssignment $a): array
    {
        $type = $a->taskType;
        $start = $a->month->copy()->startOfMonth();
        $end = $a->month->copy()->endOfMonth();

        if ($type->isManual()) {
            $sum = (float) $a->submissions()->where('status', 'verified')->sum('value');

            return [$sum, ['verified' => (int) $a->submissions()->where('status', 'verified')->count(),
                'pending' => (int) $a->submissions()->where('status', 'pending')->count()]];
        }

        return $a->subject_type === 'branch'
            ? $this->branchMetric($type->key, (int) $a->subject_id, $a, $start, $end)
            : $this->memberMetric($type->key, (int) $a->subject_id, $a, $start, $end);
    }

    protected function branchMetric(string $key, int $branchId, TaskAssignment $a, Carbon $start, Carbon $end): array
    {
        switch ($key) {
            case 'OPEN_HOURS':
                $need = (float) ($a->per_day ?? 8);
                $days = BranchAttendance::where('branch_id', $branchId)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
                    ->whereNotNull('opened_at')->get();
                $ok = 0; $hours = 0.0;
                foreach ($days as $d) {
                    $h = $this->openHours($d);
                    $hours += $h;
                    if ($h + 1e-6 >= $need) {
                        $ok++;
                    }
                }

                return [(float) $ok, ['days_open' => $days->count(), 'hours_total' => round($hours, 1), 'hours_per_day' => $need]];

            case 'OPEN_DAYS':
                $n = BranchAttendance::where('branch_id', $branchId)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
                    ->whereNotNull('opened_at')->count();

                return [(float) $n, []];

            case 'STOCK_KEPT':
                $short = BranchStockDay::where('branch_id', $branchId)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
                    ->where('is_short', true)->distinct()->count('date');
                $days = BranchStockDay::where('branch_id', $branchId)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
                    ->distinct()->count('date');

                return [(float) $short, ['days_checked' => $days, 'shortfall_days' => $short]];

            case 'RD_RENEWALS':
                return [(float) RdEntry::where('branch_id', $branchId)
                    ->whereDate('paid_on', '>=', $start->toDateString())->whereDate('paid_on', '<=', $end->toDateString())->count(), []];

            case 'BILLING':
            case 'BILLING_G10':
                $q = SalesInvoice::where('branch_id', $branchId)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString());
                if ($key === 'BILLING_G10') {
                    $q->whereHas('plan', fn ($p) => $p->whereIn('type', ['gold', 'silver']));
                }

                return [(float) $q->sum('net_total'), ['invoices' => (int) $q->count()]];
        }

        return [0.0, ['unknown' => $key]];
    }

    protected function memberMetric(string $key, int $memberId, TaskAssignment $a, Carbon $start, Carbon $end): array
    {
        $member = Member::with('rank', 'employeeProfile')->find($memberId);
        if (! $member) {
            return [0.0, ['missing_member' => true]];
        }
        $emp = $member->employeeProfile;

        switch ($key) {
            case 'ATTENDANCE':
                if (! $emp) {
                    return [0.0, ['no_employee_profile' => true]];
                }
                $n = AttendanceRecord::where('employee_profile_id', $emp->id)
                    ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())
                    ->whereNotNull('check_in_at')->whereNotNull('check_out_at')->distinct()->count('date');

                return [(float) $n, []];

            case 'BRANCH_VISITS':
                if (! $emp) {
                    return [0.0, ['no_employee_profile' => true]];
                }
                $n = EmployeeVisit::where('employee_profile_id', $emp->id)->whereNotNull('branch_id')
                    ->whereBetween('visited_at', [$start, $end->copy()->endOfDay()])
                    ->get(['branch_id', 'visited_at'])
                    ->map(fn ($v) => $v->branch_id . '@' . $v->visited_at->toDateString())->unique()->count();

                return [(float) $n, []];

            case 'ZOOM_INVITED':
                $depth = (int) ($member->rank?->depth ?? 0);
                $n = Meeting::published()->where('platform', 'zoom')->whereIn('visibility', ['members', 'public'])
                    ->whereBetween('scheduled_at', [$start, $end->copy()->endOfDay()])->forDepth($depth)->count();

                return [(float) $n, []];

            case 'ZOOM_JOINED':
            case 'ZOOM_MINUTES':
                $rows = MeetingAttendance::with('meeting')->where('member_id', $memberId)->where('source', 'zoom')
                    ->whereBetween('joined_at', [$start, $end->copy()->endOfDay()])->get()
                    ->filter(fn ($r) => $r->meeting && $r->meeting->platform === 'zoom');
                $byMeeting = $rows->groupBy('meeting_id')->map(fn ($g) => (int) $g->sum('duration_min'));
                $minutes = (int) $byMeeting->sum();
                $joined = $byMeeting->filter(function ($min, $meetingId) use ($rows) {
                    $m = $rows->firstWhere('meeting_id', $meetingId)?->meeting;
                    $need = ceil((int) ($m?->duration_min ?: 60) * self::ZOOM_VERIFIED_SHARE);

                    return $min >= $need;
                })->count();

                return $key === 'ZOOM_JOINED'
                    ? [(float) $joined, ['minutes' => $minutes, 'meetings_seen' => $byMeeting->count()]]
                    : [(float) $minutes, ['verified_meetings' => $joined]];

            case 'GENERAL_MEETINGS':
                if (! $emp) {
                    return [0.0, ['no_employee_profile' => true]];
                }
                $meetings = Meeting::published()->where('platform', 'lbox')->whereNotNull('device_id')
                    ->whereBetween('scheduled_at', [$start, $end->copy()->endOfDay()])->get();
                $attended = 0;
                foreach ($meetings as $m) {
                    $hit = EmployeeVisit::where('employee_profile_id', $emp->id)->where('device_id', $m->device_id)
                        ->whereBetween('visited_at', [
                            $m->scheduled_at->copy()->subHours(self::LBOX_MEETING_WINDOW_H),
                            $m->endsAt()->addHours(self::LBOX_MEETING_WINDOW_H),
                        ])->exists();
                    $attended += $hit ? 1 : 0;
                }

                return [(float) $attended, ['meetings_held' => $meetings->count()]];

            case 'DIRECT_NEW':
                $n = Member::where('upline_id', $memberId)
                    ->whereDate('joined_on', '>=', $start->toDateString())->whereDate('joined_on', '<=', $end->toDateString())->count();

                return [(float) $n, []];

            case 'GBV_GROWTH':
                $base = MemberMonthSnapshot::where('member_id', $memberId)->whereDate('month', $start->toDateString())->first();
                $baseGbv = (float) ($base?->gbv ?? $member->gbv);
                $growth = max(0.0, (float) $member->gbv - $baseGbv);

                return [$growth, ['gbv_start' => $baseGbv, 'gbv_now' => (float) $member->gbv, 'baseline' => $base ? 'snapshot' : 'none']];

            case 'MEET_PERSON':
                $n = SocialPost::where('poster_type', Member::class)->where('poster_id', $memberId)
                    ->whereBetween('created_at', [$start, $end->copy()->endOfDay()])
                    ->whereHas('media')->count();

                return [(float) $n, []];
        }

        return [0.0, ['unknown' => $key]];
    }

    /** Hours a branch stayed open on a day; an open day without a close tap counts up to 8 PM (or now). */
    public function openHours(BranchAttendance $d): float
    {
        if (! $d->opened_at) {
            return 0.0;
        }
        $close = $d->closed_at ?? min(Carbon::now(), $d->opened_at->copy()->setTime(self::AUTO_CLOSE_HOUR, 0));
        if ($close->lt($d->opened_at)) {
            return 0.0;
        }

        return round($d->opened_at->diffInMinutes($close) / 60, 2);
    }

    public function pct(TaskType $type, float $target, float $achieved): float
    {
        if ($type->direction === 'down') {
            $over = $achieved - $target;

            return $over <= 0 ? 100.0 : max(0.0, round(100 - $over * self::DOWN_PENALTY_PER_UNIT, 2));
        }
        if ($target <= 0) {
            return 100.0;   // nothing demanded (information-only rows)
        }

        return min(100.0, round($achieved / $target * 100, 2));
    }

    protected function status(TaskAssignment $a, float $pct, Carbon $now): string
    {
        if ($pct >= 100) {
            return 'achieved';
        }
        $end = $a->month->copy()->endOfMonth();
        if ($now->gt($end)) {
            return 'missed';
        }
        // expected share of the month elapsed, with a 10-point tolerance
        $elapsed = $now->day / $end->day * 100;

        return $pct + 10 >= $elapsed ? 'on_track' : 'behind';
    }

    // ── Daily jobs ──────────────────────────────────────────────────────────────

    /** Branches that opened but never tapped close are closed at 8 PM. Returns rows closed. */
    public function autoClose(?Carbon $date = null): int
    {
        $date ??= Carbon::today();
        $n = 0;
        BranchAttendance::whereDate('date', $date->toDateString())->whereNotNull('opened_at')->whereNull('closed_at')
            ->each(function (BranchAttendance $d) use ($date, &$n) {
                $at = $date->copy()->setTime(self::AUTO_CLOSE_HOUR, 0);
                if ($at->lt($d->opened_at)) {
                    $at = $d->opened_at->copy();
                }
                $d->update(['closed_at' => $at]);
                $n++;
            });

        return $n;
    }

    /** Snapshot every branch's metal stock against its Opening level. Returns rows written. */
    public function snapshotStock(?Carbon $date = null): int
    {
        $date ??= Carbon::today();
        $n = 0;
        Stock::query()
            ->join('catalog_products', 'catalog_products.id', '=', 'stock.catalog_product_id')
            ->join('branches', 'branches.id', '=', 'stock.branch_id')
            ->where('branches.is_active', true)
            ->where('catalog_products.material', '!=', 'cash')
            ->where('stock.order_line_id', 0)
            ->select(['stock.branch_id', 'stock.catalog_product_id', 'stock.quantity', 'stock.min_qty'])
            ->orderBy('stock.id')
            ->chunk(500, function ($rows) use ($date, &$n) {
                foreach ($rows as $s) {
                    BranchStockDay::updateOrCreate(
                        ['branch_id' => $s->branch_id, 'date' => $date->toDateString(), 'catalog_product_id' => $s->catalog_product_id],
                        ['quantity' => $s->quantity, 'opening_qty' => $s->min_qty,
                            'is_short' => $s->min_qty !== null && (float) $s->quantity < (float) $s->min_qty],
                    );
                    $n++;
                }
            });

        return $n;
    }

    // ── Month lock + score ──────────────────────────────────────────────────────

    /** Final measure, achieved/missed, score per subject, lock. Returns scores written. */
    public function lockMonth(?Carbon $month = null): int
    {
        $month = self::monthOf($month);
        $after = $month->copy()->endOfMonth()->addSecond();
        $subjects = [];

        TaskAssignment::with('taskType')->forMonth($month)->whereNull('locked_at')
            ->chunkById(200, function ($rows) use ($after, &$subjects) {
                foreach ($rows as $a) {
                    $this->measureAssignment($a, $after);
                    $a->forceFill(['locked_at' => Carbon::now()])->save();
                    $subjects[$a->subject_type . ':' . $a->subject_id] = true;
                }
            });

        $n = 0;
        foreach (array_keys($subjects) as $k) {
            [$type, $id] = explode(':', $k);
            $this->score($type, (int) $id, $month, lock: true);
            $n++;
        }

        return $n;
    }

    /** Weighted average of capped task % — persisted (open or locked). */
    public function score(string $type, int $id, ?Carbon $month = null, bool $lock = false): TaskScore
    {
        $month = self::monthOf($month);
        $rows = TaskAssignment::forMonth($month)->forSubject($type, $id)->get();
        $weighted = $rows->where('weight', '>', 0);
        $wSum = (int) $weighted->sum('weight');
        $pct = $wSum > 0 ? round($weighted->sum(fn ($a) => min(100, (float) $a->pct) * $a->weight) / $wSum, 2) : 0.0;

        $score = TaskScore::where('subject_type', $type)->where('subject_id', $id)->whereDate('month', $month->toDateString())->first()
            ?? new TaskScore(['month' => $month->toDateString(), 'subject_type' => $type, 'subject_id' => $id]);
        if ($score->status === 'locked' && ! $lock) {
            return $score;   // never recompute a locked month silently
        }
        $score->fill([
            'score_pct' => $pct,
            'tasks_total' => $rows->count(),
            'tasks_achieved' => $rows->where('status', 'achieved')->count(),
        ]);
        if ($lock) {
            $score->status = 'locked';
            $score->locked_at = Carbon::now();
        }
        $score->save();

        return $score;
    }

    // ── Read side (app + admin) ─────────────────────────────────────────────────

    /** The branch a member runs (dealer login with this member code), if any. */
    public static function branchRunBy(Member $member): ?Branch
    {
        $branchId = User::where('member_code', $member->member_code)->whereNotNull('branch_id')->value('branch_id');

        return $branchId ? Branch::find($branchId) : null;
    }

    /** Everything the app's My Status → Monthly Tasks block needs. */
    public function summary(Member $member, ?Carbon $month = null): array
    {
        $month = self::monthOf($month);
        $branch = self::branchRunBy($member);

        $present = fn (TaskAssignment $a) => [
            'id' => $a->id,
            'key' => $a->taskType->key,
            'title' => $a->title(),
            'description' => $a->taskType->description,
            'unit' => $a->taskType->unit,
            'mode' => $a->taskType->mode,
            'direction' => $a->taskType->direction,
            'target' => (float) $a->target,
            'per_day' => $a->per_day !== null ? (float) $a->per_day : null,
            'achieved' => (float) $a->achieved,
            'target_label' => $a->taskType->format((float) $a->target),
            'achieved_label' => $a->taskType->format((float) $a->achieved),
            'pct' => (float) $a->pct,
            'weight' => (int) $a->weight,
            'status' => $a->status,
            'detail' => $a->detail ?? [],
            'locked' => $a->isLocked(),
            'note' => $a->note,
            'pending_submissions' => $a->taskType->isManual() ? $a->submissions()->where('status', 'pending')->count() : 0,
        ];

        $mine = TaskAssignment::with('taskType')->forMonth($month)->forSubject('member', $member->id)
            ->get()->sortBy(fn ($a) => $a->taskType->sort)->values()->map($present);
        $branchRows = $branch
            ? TaskAssignment::with('taskType')->forMonth($month)->forSubject('branch', $branch->id)
                ->get()->sortBy(fn ($a) => $a->taskType->sort)->values()->map($present)
            : collect();

        $score = TaskScore::where('subject_type', 'member')->where('subject_id', $member->id)
            ->whereDate('month', $month->toDateString())->first();
        if (! $score && $mine->isNotEmpty()) {
            $score = $this->score('member', $member->id, $month);
        }
        $branchScore = $branch ? TaskScore::where('subject_type', 'branch')->where('subject_id', $branch->id)
            ->whereDate('month', $month->toDateString())->first() : null;

        // Direct downlines (answer 10: uplines see their people's progress).
        $downlines = Member::where('upline_id', $member->id)->where('status', 'active')->get(['id', 'member_code', 'name'])
            ->map(function (Member $d) use ($month) {
                $s = TaskScore::where('subject_type', 'member')->where('subject_id', $d->id)->whereDate('month', $month->toDateString())->first();
                $rows = TaskAssignment::forMonth($month)->forSubject('member', $d->id)->get();

                return [
                    'member_code' => $d->member_code, 'name' => $d->name,
                    'score_pct' => $s?->effectivePct(), 'tasks_total' => $rows->count(),
                    'tasks_achieved' => $rows->where('status', 'achieved')->count(),
                ];
            })->values();

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'score' => $score ? ['pct' => $score->effectivePct(), 'locked' => $score->status === 'locked',
                'tasks_total' => $score->tasks_total, 'tasks_achieved' => $score->tasks_achieved,
                'label' => self::scoreLabel($score->effectivePct())] : null,
            'pay_note' => 'Your score scales this month\'s turnover-based salary and payroll (Promotional Incentive is not affected).',
            'employee_tasks' => $mine,
            'branch' => $branch ? [
                'id' => $branch->id, 'name' => $branch->name, 'level' => Branch::levelLabel($branch->level),
                'score' => $branchScore ? ['pct' => $branchScore->effectivePct(), 'locked' => $branchScore->status === 'locked'] : null,
                'tasks' => $branchRows,
            ] : null,
            'downlines' => $downlines,
        ];
    }

    /** Day-by-day stock chart for a branch/month: one entry per calendar day. */
    public function stockChart(Branch $branch, ?Carbon $month = null, ?int $productId = null): array
    {
        $month = self::monthOf($month);
        $days = $month->daysInMonth;
        $rows = BranchStockDay::with('catalogProduct')->where('branch_id', $branch->id)
            ->whereDate('date', '>=', $month->toDateString())->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->when($productId, fn ($q) => $q->where('catalog_product_id', $productId))
            ->get();

        $products = $rows->groupBy('catalog_product_id')->map(fn ($g) => [
            'id' => $g->first()->catalog_product_id,
            'name' => \App\Support\Translatable::pick($g->first()->catalogProduct?->name) ?: ($g->first()->catalogProduct?->code ?? '—'),
            'short_days' => $g->where('is_short', true)->count(),
        ])->values();

        $series = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = $month->copy()->day($d)->toDateString();
            $dayRows = $rows->filter(fn ($r) => $r->date->toDateString() === $date);
            if ($dayRows->isEmpty()) {
                $series[] = ['day' => $d, 'date' => $date, 'checked' => false, 'quantity' => null, 'opening' => null, 'short' => false, 'ratio' => null];
                continue;
            }
            // Worst item of the day drives the bar: lowest quantity/opening ratio.
            $worst = $dayRows->sortBy(fn ($r) => $r->opening_qty > 0 ? (float) $r->quantity / (float) $r->opening_qty : 9)->first();
            $ratio = $worst->opening_qty > 0 ? round((float) $worst->quantity / (float) $worst->opening_qty, 3) : null;
            $series[] = [
                'day' => $d, 'date' => $date, 'checked' => true,
                'quantity' => (float) $worst->quantity, 'opening' => $worst->opening_qty !== null ? (float) $worst->opening_qty : null,
                'short' => $dayRows->contains('is_short', true), 'ratio' => $ratio,
                'product' => \App\Support\Translatable::pick($worst->catalogProduct?->name) ?: ($worst->catalogProduct?->code ?? null),
            ];
        }

        return [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'month' => $month->format('Y-m'), 'days' => $days,
            'shortfall_days' => collect($series)->where('short', true)->count(),
            'products' => $products, 'series' => $series,
        ];
    }

    public static function scoreLabel(float $pct): string
    {
        return match (true) {
            $pct >= 100 => 'Achieved',
            $pct >= 75 => 'On track',
            $pct >= 50 => 'Behind',
            default => 'At risk',
        };
    }

    public static function monthOf(?\DateTimeInterface $month): Carbon
    {
        return ($month ? Carbon::instance($month) : Carbon::now())->startOfMonth()->startOfDay();
    }
}
