<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per employee per day. App check-ins carry the front-camera selfie and
 * GPS fix; HQ can mark manual entries; the L-BOX device is a future source.
 */
class AttendanceRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'checkout_lat' => 'decimal:7',
        'checkout_lng' => 'decimal:7',
        'accuracy_m' => 'decimal:2',
    ];

    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function markedBy() { return $this->belongsTo(User::class, 'marked_by'); }

    /** Payroll weight of this day: present/paid leave = 1, half day = 0.5, else 0. */
    public function dayValue(): float
    {
        return match ($this->status) {
            'present', 'paid_leave' => 1.0,
            'half_day' => 0.5,
            default => 0.0,
        };
    }
}
