<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A month's payroll: one run per (year, month), holding a payslip per employee. */
class PayrollRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross_total' => 'decimal:2',
        'deduction_total' => 'decimal:2',
        'net_total' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function payslips() { return $this->hasMany(Payslip::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }
}
