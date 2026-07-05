<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's request for a date range off. Pending until HQ approves it as
 * paid_leave/leave (which writes the attendance rows payroll reads) or rejects it.
 */
class LeaveRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'decided_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function days(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }
}
