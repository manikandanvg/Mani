<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Payroll identity of a ranked distributor. One per member; created when a
 * distributor holding a TBP stage is enrolled onto the payroll.
 */
class EmployeeProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'basic_pct' => 'decimal:2',
        'tds_pct' => 'decimal:2',
        'pf_enabled' => 'boolean',
        'esi_enabled' => 'boolean',
        'date_of_joining' => 'date',
    ];

    public function member() { return $this->belongsTo(Member::class); }
    public function rank() { return $this->belongsTo(Rank::class); }
    public function attendanceRecords() { return $this->hasMany(AttendanceRecord::class); }
    public function payslips() { return $this->hasMany(Payslip::class); }

    /** Effective TDS % — profile override, else the TBP stage default. */
    public function effectiveTdsPct(): float
    {
        return (float) ($this->tds_pct ?? $this->rank?->tds_pct ?? 0);
    }
}
