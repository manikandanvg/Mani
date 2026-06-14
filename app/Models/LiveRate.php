<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveRate extends Model
{
    protected $casts = [
        'gold' => 'decimal:2',
        'silver' => 'decimal:2',
        'diamond' => 'decimal:2',
        'is_override' => 'boolean',
        'effective_at' => 'datetime',
    ];

    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }

    /** Latest effective rate for a country (price history kept as separate rows). */
    public static function latestFor(string $country = 'IN'): ?self
    {
        static $cache = [];

        return $cache[$country] ??= static::where('country', $country)->latest('effective_at')->first();
    }

    /** The metal rate for a branch's region — quoted natively in that region's currency. */
    public static function forBranch(Branch $branch): ?self
    {
        return static::latestFor($branch->country ?: 'IN');
    }
}
