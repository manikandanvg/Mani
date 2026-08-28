<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One member/branch × task × month, carrying the measured progress. Status:
 *   pending  — nothing measured yet
 *   on_track — progress ≥ the share of the month elapsed
 *   behind   — below that share
 *   achieved — target reached (locked months only: achieved / missed)
 */
class TaskAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'target' => 'decimal:2',
        'per_day' => 'decimal:2',
        'weight' => 'integer',
        'achieved' => 'decimal:2',
        'pct' => 'decimal:2',
        'detail' => 'array',
        'measured_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Pending', 'on_track' => 'On track', 'behind' => 'Behind', 'achieved' => 'Achieved', 'missed' => 'Missed',
    ];

    public function taskType() { return $this->belongsTo(TaskType::class); }
    public function submissions() { return $this->hasMany(TaskSubmission::class); }
    public function member() { return $this->belongsTo(Member::class, 'subject_id')->where('task_assignments.subject_type', 'member'); }
    public function branch() { return $this->belongsTo(Branch::class, 'subject_id')->where('task_assignments.subject_type', 'branch'); }

    /** The Member or Branch this assignment belongs to. */
    public function subject(): Member|Branch|null
    {
        return $this->subject_type === 'member'
            ? Member::find($this->subject_id)
            : Branch::find($this->subject_id);
    }

    public function subjectName(): string
    {
        $s = $this->subject();

        return $s instanceof Member ? ($s->member_code . ' — ' . $s->name) : ($s?->name ?? '—');
    }

    public function title(): string
    {
        return $this->title ?: ($this->taskType?->name ?? '');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function scopeForMonth($q, \DateTimeInterface $month)
    {
        return $q->whereDate('month', \Illuminate\Support\Carbon::instance($month)->startOfMonth()->toDateString());
    }

    public function scopeForSubject($q, string $type, int $id)
    {
        return $q->where('subject_type', $type)->where('subject_id', $id);
    }
}
