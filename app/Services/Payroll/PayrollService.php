<?php

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll engine (2026-07 board/auditor mandate). Salary is prorated from
 * attendance (present = 1 day, half day = 0.5), then Indian statutory deductions
 * apply:
 *
 *  - EPF: 12% employee + 12% employer on BASIC (basic_pct of gross, default 50%),
 *    with the statutory wage ceiling of ₹15,000/month on the PF base.
 *  - ESI: 0.75% employee + 3.25% employer on gross, only while the employee's
 *    MONTHLY salary is within the ₹21,000 eligibility ceiling.
 *  - TDS: flat % from the profile (falls back to the TBP stage default) — the
 *    board's "lower ranking categories" rule. Slab-based Sec-192 TDS can replace
 *    this later without touching the run/payslip structure.
 *
 * Rates/ceilings are constants here and frozen onto each payslip's `snapshot`,
 * so historical payslips stay correct when the law changes.
 */
class PayrollService
{
    public const PF_EMPLOYEE_PCT = 12.0;

    public const PF_EMPLOYER_PCT = 12.0;

    /** Statutory EPF wage ceiling — PF is computed on basic capped at this. */
    public const PF_WAGE_CEILING = 15000.0;

    public const ESI_EMPLOYEE_PCT = 0.75;

    public const ESI_EMPLOYER_PCT = 3.25;

    /** ESI applies only while the monthly wage is at or below this. */
    public const ESI_WAGE_CEILING = 21000.0;

    /**
     * Generate (or regenerate) the run for a period. Existing DRAFT runs are
     * rebuilt from current attendance; approved/paid runs refuse regeneration.
     */
    public function generate(int $year, int $month, ?int $userId = null): PayrollRun
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $workingDays = (int) $start->daysInMonth;

        return DB::transaction(function () use ($year, $month, $start, $end, $workingDays, $userId) {
            $run = PayrollRun::firstOrNew(['period_year' => $year, 'period_month' => $month]);
            if ($run->exists && $run->status !== 'draft') {
                throw new \RuntimeException("Payroll {$run->periodLabel()} is already {$run->status}.");
            }

            $run->fill(['working_days' => $workingDays, 'status' => 'draft', 'generated_by' => $userId])->save();
            $run->payslips()->delete();   // rebuild draft from current attendance

            $employees = EmployeeProfile::query()
                ->where('status', 'active')
                ->whereDate('date_of_joining', '<=', $end->toDateString())
                ->with('rank')
                ->get();

            $gross = $deductions = $net = 0.0;

            foreach ($employees as $employee) {
                $slip = $this->buildPayslip($run, $employee, $start, $end, $workingDays);
                $gross += (float) $slip->gross;
                $deductions += $slip->deductionTotal();
                $net += (float) $slip->net;
            }

            $run->fill([
                'gross_total' => round($gross, 2),
                'deduction_total' => round($deductions, 2),
                'net_total' => round($net, 2),
            ])->save();

            return $run;
        });
    }

    public function approve(PayrollRun $run, ?int $userId = null): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new \RuntimeException("Only draft runs can be approved (run is {$run->status}).");
        }

        $run->fill(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => Carbon::now()])->save();

        // Payslip-ready acknowledgement (board 2026-08-11: push + inbox).
        foreach ($run->payslips()->with('employee.member')->get() as $slip) {
            \App\Services\Push\Notifier::to($slip->employee?->member, 'wallet',
                'Payslip ready — ' . $run->periodLabel(),
                'Your salary slip for ' . $run->periodLabel() . ' is ready: net ₹'
                    . \App\Support\Money::group((float) $slip->net) . '. Tap to view.',
                route: '/payslips/' . $slip->id,
            );
        }

        return $run;
    }

    /** Mark the whole run disbursed (bank transfer happens outside the system). */
    public function markPaid(PayrollRun $run, ?string $reference = null): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new \RuntimeException("Only approved runs can be paid (run is {$run->status}).");
        }

        DB::transaction(function () use ($run, $reference) {
            $run->payslips()->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'payment_reference' => $reference,
            ]);
            $run->fill(['status' => 'paid', 'paid_at' => Carbon::now()])->save();
        });

        // Salary-paid acknowledgement.
        foreach ($run->payslips()->with('employee.member')->get() as $slip) {
            \App\Services\Push\Notifier::to($slip->employee?->member, 'wallet',
                'Salary paid — ' . $run->periodLabel(),
                \App\Support\Money::inr((float) $slip->net) . ' disbursed'
                    . ($reference ? " (ref {$reference})" : '') . '. Thank you for your service.',
                route: '/payslips/' . $slip->id,
            );
        }

        return $run;
    }

    protected function buildPayslip(PayrollRun $run, EmployeeProfile $employee, Carbon $start, Carbon $end, int $workingDays): Payslip
    {
        // whereDate on both bounds — the model's date cast stores 'Y-m-d 00:00:00',
        // which a string whereBetween upper bound of 'Y-m-d' would exclude.
        $records = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        $daysPresent = round($records->sum(fn (AttendanceRecord $r) => $r->dayValue()), 1);
        $payableDays = min($daysPresent, $workingDays);

        $monthlySalary = (float) $employee->monthly_salary;
        // Monthly Tasks score (board 2026-08-29): payroll gross is scaled by the member's
        // task score for the month (1.0 when no tasks were assigned).
        $taskFactor = \App\Models\TaskScore::factorFor((int) $employee->member_id, $start);
        $grossAmount = round($monthlySalary * $payableDays / max($workingDays, 1) * $taskFactor, 2);
        $basic = round($grossAmount * (float) $employee->basic_pct / 100, 2);

        $pfEmployee = $pfEmployer = 0.0;
        if ($employee->pf_enabled && $basic > 0) {
            $pfBase = min($basic, self::PF_WAGE_CEILING);
            $pfEmployee = round($pfBase * self::PF_EMPLOYEE_PCT / 100, 2);
            $pfEmployer = round($pfBase * self::PF_EMPLOYER_PCT / 100, 2);
        }

        // ESI eligibility is judged on the contracted monthly wage, contributions on actual gross.
        $esiEmployee = $esiEmployer = 0.0;
        if ($employee->esi_enabled && $monthlySalary > 0 && $monthlySalary <= self::ESI_WAGE_CEILING) {
            $esiEmployee = round($grossAmount * self::ESI_EMPLOYEE_PCT / 100, 2);
            $esiEmployer = round($grossAmount * self::ESI_EMPLOYER_PCT / 100, 2);
        }

        $tdsMode = (string) config('payroll.tds_mode', 'flat');
        $tdsPct = $employee->effectiveTdsPct();
        $tds = $tdsMode === 'slab'
            ? $this->slabTds($monthlySalary, $grossAmount)
            : round($grossAmount * $tdsPct / 100, 2);

        $netAmount = round($grossAmount - $pfEmployee - $esiEmployee - $tds, 2);

        return Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_profile_id' => $employee->id,
            'days_present' => $daysPresent,
            'payable_days' => $payableDays,
            'monthly_salary' => $monthlySalary,
            'gross' => $grossAmount,
            'basic' => $basic,
            'pf_employee' => $pfEmployee,
            'pf_employer' => $pfEmployer,
            'esi_employee' => $esiEmployee,
            'esi_employer' => $esiEmployer,
            'tds' => $tds,
            'net' => $netAmount,
            'snapshot' => [
                'basic_pct' => (float) $employee->basic_pct,
                'pf_pct' => [self::PF_EMPLOYEE_PCT, self::PF_EMPLOYER_PCT],
                'pf_ceiling' => self::PF_WAGE_CEILING,
                'esi_pct' => [self::ESI_EMPLOYEE_PCT, self::ESI_EMPLOYER_PCT],
                'esi_ceiling' => self::ESI_WAGE_CEILING,
                'tds_mode' => $tdsMode,
                'tds_pct' => $tdsMode === 'flat' ? $tdsPct : null,
                'working_days' => $workingDays,
                'task_score_pct' => round($taskFactor * 100, 2),
            ],
        ]);
    }

    /**
     * Sec-192-style monthly TDS (config 'slab' mode): annualise the contracted
     * salary less the standard deduction, run the slabs + Sec-87A rebate + cess,
     * then withhold 1/12th prorated to this month's actual gross.
     */
    protected function slabTds(float $monthlySalary, float $grossAmount): float
    {
        if ($monthlySalary <= 0 || $grossAmount <= 0) {
            return 0.0;
        }

        $taxable = max(0, $monthlySalary * 12 - (float) config('payroll.standard_deduction', 0));

        if ($taxable <= (float) config('payroll.rebate_limit', 0)) {
            return 0.0;   // fully rebated under Sec 87A
        }

        $tax = 0.0;
        $lower = 0.0;
        foreach ((array) config('payroll.slabs', []) as [$upper, $rate]) {
            $cap = $upper === null ? $taxable : min($taxable, (float) $upper);
            if ($cap > $lower) {
                $tax += ($cap - $lower) * (float) $rate / 100;
            }
            if ($upper === null || $taxable <= (float) $upper) {
                break;
            }
            $lower = (float) $upper;
        }

        $tax *= 1 + (float) config('payroll.cess_pct', 0) / 100;

        // 1/12th per month, scaled by how much of the month was actually earned.
        return round($tax / 12 * ($grossAmount / $monthlySalary), 2);
    }
}
