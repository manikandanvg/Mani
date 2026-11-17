<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rank extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name' => 'array',           // translatable {en:.., ta:..}
        'reward_title' => 'array',   // the award in words, translatable (board 2026-11-17)
        'tier_template' => 'array',
        'is_active' => 'boolean',
    ];

    /** Monthly task rules for employees at this TBP stage (board 2026-08-29). */
    public function taskTargets() { return $this->hasMany(TaskTarget::class); }
}
