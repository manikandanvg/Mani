<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'ic_schedule' => 'array',
        'level_schedule' => 'array',
        'min_value' => 'decimal:2',
        'allocation_pct' => 'decimal:3',
        'cbc_value' => 'decimal:3',
        'is_active' => 'boolean',
        'counts_for_rank' => 'boolean',
        'rank_factor' => 'decimal:4',
    ];

    public function mou() { return $this->belongsTo(Mou::class); }

    /**
     * Ranking-BV factor (legacy rabvreset): excluded plans → 0; explicit rank_factor override
     * (e.g. Area Distributor → 0.10); else allocation 100% → ×1, otherwise the remainder.
     */
    public function rankFactor(): float
    {
        if (! $this->counts_for_rank) {
            return 0.0;
        }
        if ($this->rank_factor !== null) {
            return (float) $this->rank_factor;
        }
        $alloc = (float) $this->allocation_pct;

        return $alloc >= 100 ? 1.0 : max(0.0, (100 - $alloc) / 100);
    }
}
