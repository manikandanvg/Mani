<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A monthly-task rule: employee tasks are set per TBP stage (rank_id, managed on
 * the Ranks page), branch tasks per branch level. The month roll turns every active
 * rule into task_assignments for the members / branches that match it.
 */
class TaskTarget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target' => 'decimal:2',
        'per_day' => 'decimal:2',
        'weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public function taskType() { return $this->belongsTo(TaskType::class); }
    public function rank() { return $this->belongsTo(Rank::class); }

    public function appliesToLabel(): string
    {
        if ($this->rank_id) {
            return \App\Support\Translatable::pick($this->rank?->name) ?: ('Rank #' . $this->rank_id);
        }

        return Branch::levelLabel((string) $this->branch_level) . ' branches';
    }
}
