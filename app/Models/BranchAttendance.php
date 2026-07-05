<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One branch-day: opened when the first RFID card touches the branch's L-BOX
 * in the morning, closing time stamped by check-out taps. A branch with no row
 * (or no opened_at) today is OFFLINE.
 */
class BranchAttendance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'closed_by');
    }

    /** Is this branch open (online) today? */
    public static function isOpenToday(int $branchId): bool
    {
        return static::where('branch_id', $branchId)
            ->whereDate('date', Carbon::today()->toDateString())
            ->whereNotNull('opened_at')
            ->exists();
    }
}
