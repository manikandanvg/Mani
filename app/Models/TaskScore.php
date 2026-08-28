<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Month score per member / branch = weighted average of each task's capped %.
 * Locked on the 1st of the next month. Member scores scale that month's GAP
 * (turnover-based salary) and the payroll gross — CBC is exempt (board 2026-08-29).
 */
class TaskScore extends Model
{
    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'score_pct' => 'decimal:2',
        'adjusted_pct' => 'decimal:2',
        'tasks_total' => 'integer',
        'tasks_achieved' => 'integer',
        'locked_at' => 'datetime',
    ];

    public function adjuster() { return $this->belongsTo(User::class, 'adjusted_by'); }

    /** The % that applies: HQ's adjustment wins over the computed score. */
    public function effectivePct(): float
    {
        return (float) ($this->adjusted_pct ?? $this->score_pct);
    }

    /**
     * Pay factor (0..1) for a member's month — 1.0 when no score exists (no tasks were
     * assigned, so nothing is withheld). Used by CommissionService::runGap and PayrollService.
     */
    public static function factorFor(int $memberId, \DateTimeInterface $month): float
    {
        $row = static::where('subject_type', 'member')->where('subject_id', $memberId)
            ->whereDate('month', Carbon::instance($month)->startOfMonth()->toDateString())
            ->first();

        return $row ? max(0.0, min(1.0, $row->effectivePct() / 100)) : 1.0;
    }

    public function subjectName(): string
    {
        if ($this->subject_type === 'member') {
            $m = Member::find($this->subject_id);

            return $m ? ($m->member_code . ' — ' . $m->name) : '—';
        }

        return Branch::find($this->subject_id)?->name ?? '—';
    }
}
