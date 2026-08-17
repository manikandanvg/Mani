<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One app install (items 16/17b): phone + distributor + device uid + biometric flags. */
class MobileDevice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'biometric_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function member() { return $this->belongsTo(Member::class); }
}
