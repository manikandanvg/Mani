<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One RFID check-in AWAY from home: an employee tapping another branch's L-BOX or
 * the meeting-arena box (branch_id null). Payroll's daily attendance is separate.
 */
class EmployeeVisit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
