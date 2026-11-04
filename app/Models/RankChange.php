<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One TBP-stage (rank) shift of a distributor, with the date and the BV that caused it
 * (user 2026-11-04). Written by NetworkService::logRankChange on both the billing-time path
 * and the bulk ranks:recompute; read on System → Rank Changes.
 */
class RankChange extends Model
{
    public const SOURCES = ['billing' => 'Billing', 'recompute' => 'Recompute (cut-off / console)', 'cancel' => 'Invoice cancelled'];

    protected $guarded = [];

    protected $casts = [
        'changed_at' => 'datetime',
        'unpure_bv' => 'decimal:2',
        'unpure_gbv' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fromRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'from_rank_id');
    }

    public function toRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'to_rank_id');
    }
}
