<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One employee's salary for one payroll run — attendance-prorated, statutory deductions applied. */
class Payslip extends Model
{
    protected $guarded = [];

    protected $casts = [
        'days_present' => 'decimal:1',
        'payable_days' => 'decimal:1',
        'monthly_salary' => 'decimal:2',
        'gross' => 'decimal:2',
        'basic' => 'decimal:2',
        'pf_employee' => 'decimal:2',
        'pf_employer' => 'decimal:2',
        'esi_employee' => 'decimal:2',
        'esi_employer' => 'decimal:2',
        'tds' => 'decimal:2',
        'net' => 'decimal:2',
        'paid_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function employee() { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }

    /** Employee-side deductions (what comes off the gross). */
    public function deductionTotal(): float
    {
        return round((float) $this->pf_employee + (float) $this->esi_employee + (float) $this->tds, 2);
    }
}
