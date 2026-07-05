<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payslip;
use App\Models\Rank;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payroll engine (2026-07 board mandate): enrolment from TBP stages, attendance
 * proration, and the statutory PF / ESI / TDS maths.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Rank $stage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stage = Rank::create([
            'code' => 'TALUK_DIRECTOR', 'name' => ['en' => 'Taluk Admin'], 'depth' => 1,
            'target_bv' => 50000, 'monthly_salary' => 20000, 'tds_pct' => 5,
        ]);
    }

    protected function makeEmployee(string $code = 'PAY1', array $overrides = []): Member
    {
        $member = Member::create([
            'member_code' => $code, 'name' => "Emp {$code}", 'phone' => '90000' . rand(10000, 99999),
            'joined_on' => now()->subMonths(2), 'placement' => 'level',
            'rank_id' => $this->stage->id, 'status' => 'active',
        ]);
        app(EmployeeService::class)->enroll($member, array_merge([
            'date_of_joining' => now()->subMonths(2)->toDateString(),
        ], $overrides));

        return $member->fresh('employeeProfile');
    }

    public function test_enrolment_copies_stage_defaults_and_base_rank_is_rejected(): void
    {
        $member = $this->makeEmployee();
        $profile = $member->employeeProfile;

        $this->assertSame('EMP-PAY1', $profile->employee_code);
        $this->assertSame('Taluk Admin', $profile->designation);
        $this->assertSame(20000.0, (float) $profile->monthly_salary);
        $this->assertSame(5.0, $profile->effectiveTdsPct());

        $base = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $plain = Member::create([
            'member_code' => 'PAY0', 'name' => 'Base', 'phone' => '9000000050',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $base->id, 'status' => 'active',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(EmployeeService::class)->enroll($plain);
    }

    public function test_rank_promotion_auto_enrols_but_never_rehires_a_removed_employee(): void
    {
        $base = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $mkMember = fn (string $code, ?int $uplineId = null) => Member::create([
            'member_code' => $code, 'name' => "M {$code}", 'phone' => '90001' . rand(10000, 99999),
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $base->id, 'status' => 'active',
            'upline_id' => $uplineId, 'unpure_bv' => 60000,
        ]);

        // Entry gate: own unpure_bv ≥ 50k AND one direct downline ≥ 50k.
        $senior = $mkMember('AUTO1');
        $mkMember('AUTO2', $senior->id);

        app(\App\Services\NetworkService::class)->evaluateRank($senior->id);

        $senior->refresh();
        $this->assertSame($this->stage->id, $senior->rank_id);
        $profile = $senior->employeeProfile;
        $this->assertNotNull($profile, 'Promotion onto a TBP stage must auto-enrol the member.');
        $this->assertSame('EMP-AUTO1', $profile->employee_code);
        $this->assertSame(20000.0, (float) $profile->monthly_salary);

        // HQ removes the employee — a later re-promotion must NOT silently rehire.
        $profile->delete();
        app(\App\Services\NetworkService::class)->evaluateRank($senior->id);
        $this->assertNull($senior->fresh()->employeeProfile);

        // Batch recompute enrols fresh promotions but leaves the removed one removed.
        $other = $mkMember('AUTO3');
        $mkMember('AUTO4', $other->id);
        app(\App\Services\NetworkService::class)->recomputeRanks();

        $this->assertNull($senior->fresh()->employeeProfile);
        $this->assertNotNull($other->fresh()->employeeProfile, 'Batch rank recompute must enrol new stage holders.');
    }

    public function test_run_prorates_salary_from_attendance_and_applies_pf_esi_tds(): void
    {
        $member = $this->makeEmployee();
        $profile = $member->employeeProfile;
        $attendance = app(AttendanceService::class);

        // Last month: 15 present days + 2 half days = 16 payable days.
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        foreach (range(1, 15) as $d) {
            $attendance->markManual($profile, $period->copy()->day($d)->toDateString(), 'present');
        }
        $attendance->markManual($profile, $period->copy()->day(16)->toDateString(), 'half_day');
        $attendance->markManual($profile, $period->copy()->day(17)->toDateString(), 'half_day');
        $attendance->markManual($profile, $period->copy()->day(18)->toDateString(), 'leave');

        $run = app(PayrollService::class)->generate($period->year, $period->month);
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $profile->id)->firstOrFail();

        $days = (int) $period->daysInMonth;
        $gross = round(20000 * 16 / $days, 2);
        $basic = round($gross * 0.50, 2);          // basic_pct default 50, under the 15k PF ceiling
        $pf = round($basic * 0.12, 2);
        $esi = round($gross * 0.0075, 2);          // monthly salary 20k ≤ 21k ceiling → ESI applies
        $esiEmployer = round($gross * 0.0325, 2);
        $tds = round($gross * 0.05, 2);

        $this->assertSame(16.0, (float) $slip->payable_days);
        $this->assertSame($gross, (float) $slip->gross);
        $this->assertSame($basic, (float) $slip->basic);
        $this->assertSame($pf, (float) $slip->pf_employee);
        $this->assertSame($pf, (float) $slip->pf_employer);
        $this->assertSame($esi, (float) $slip->esi_employee);
        $this->assertSame($esiEmployer, (float) $slip->esi_employer);
        $this->assertSame($tds, (float) $slip->tds);
        $this->assertSame(round($gross - $pf - $esi - $tds, 2), (float) $slip->net);

        $this->assertSame((float) $slip->gross, (float) $run->fresh()->gross_total);
    }

    public function test_pf_ceiling_and_esi_cutoff_for_high_salary(): void
    {
        // ₹40k salary: basic 20k → PF capped at 15k base; ESI not applicable (> 21k).
        $member = $this->makeEmployee('PAY2', ['monthly_salary' => 40000, 'tds_pct' => 10]);
        $profile = $member->employeeProfile;

        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $days = (int) $period->daysInMonth;
        foreach (range(1, $days) as $d) {
            app(AttendanceService::class)->markManual($profile, $period->copy()->day($d)->toDateString(), 'present');
        }

        $run = app(PayrollService::class)->generate($period->year, $period->month);
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $profile->id)->firstOrFail();

        $this->assertSame(40000.0, (float) $slip->gross);              // full month
        $this->assertSame(20000.0, (float) $slip->basic);
        $this->assertSame(1800.0, (float) $slip->pf_employee);         // 12% of the 15k ceiling
        $this->assertSame(0.0, (float) $slip->esi_employee);           // over the 21k cutoff
        $this->assertSame(4000.0, (float) $slip->tds);                 // 10% override beats the stage's 5%
    }

    public function test_lifecycle_draft_regenerates_but_approved_refuses_and_paid_marks_slips(): void
    {
        $member = $this->makeEmployee('PAY3');
        $profile = $member->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(1)->toDateString(), 'present');

        $svc = app(PayrollService::class);
        $run = $svc->generate($period->year, $period->month);
        $this->assertSame('draft', $run->status);

        // a draft regenerates in place (same run id, rebuilt slips)
        $again = $svc->generate($period->year, $period->month);
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, $again->payslips()->count());

        $svc->approve($again);
        $this->assertSame('approved', $again->fresh()->status);

        try {
            $svc->generate($period->year, $period->month);
            $this->fail('Approved run must refuse regeneration.');
        } catch (\RuntimeException) {
        }

        $svc->markPaid($again->fresh(), 'BANK-BATCH-77');
        $paid = $again->fresh();
        $this->assertSame('paid', $paid->status);
        $this->assertSame('paid', $paid->payslips()->first()->status);
        $this->assertSame('BANK-BATCH-77', $paid->payslips()->first()->payment_reference);
    }

    public function test_paid_leave_counts_as_a_full_payable_day_but_unpaid_leave_does_not(): void
    {
        $member = $this->makeEmployee('PAY5');
        $profile = $member->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();

        app(AttendanceService::class)->markManual($profile, $period->copy()->day(1)->toDateString(), 'present');
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(2)->toDateString(), 'paid_leave');
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(3)->toDateString(), 'leave');

        $run = app(PayrollService::class)->generate($period->year, $period->month);
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $profile->id)->firstOrFail();

        $this->assertSame(2.0, (float) $slip->payable_days);
    }

    public function test_slab_tds_mode_taxes_annualised_salary_and_rebates_small_incomes(): void
    {
        config(['payroll.tds_mode' => 'slab']);

        // ₹1.5L/month → annual 18L − 75k std deduction = 17.25L taxable.
        // Slabs: 4-8L@5% 20k + 8-12L@10% 40k + 12-16L@15% 60k + 16-17.25L@20% 25k = 1.45L; ×1.04 cess = 1,50,800.
        $high = $this->makeEmployee('PAY6', ['monthly_salary' => 150000, 'esi_enabled' => false]);
        // ₹20k/month → taxable 1.65L ≤ 12L rebate limit → zero TDS.
        $low = $this->makeEmployee('PAY7');

        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $days = (int) $period->daysInMonth;
        foreach ([$high, $low] as $m) {
            foreach (range(1, $days) as $d) {
                app(AttendanceService::class)->markManual($m->employeeProfile, $period->copy()->day($d)->toDateString(), 'present');
            }
        }

        $run = app(PayrollService::class)->generate($period->year, $period->month);

        $highSlip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $high->employeeProfile->id)->firstOrFail();
        $lowSlip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $low->employeeProfile->id)->firstOrFail();

        $this->assertSame(round(150800 / 12, 2), (float) $highSlip->tds);
        $this->assertSame('slab', $highSlip->snapshot['tds_mode']);
        $this->assertSame(0.0, (float) $lowSlip->tds);
    }

    public function test_payslip_pdf_downloads_via_signed_url_and_rejects_tampering(): void
    {
        $member = $this->makeEmployee('PAY8');
        $profile = $member->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(1)->toDateString(), 'present');
        app(PayrollService::class)->generate($period->year, $period->month);

        Sanctum::actingAs($member, ['*']);
        $url = $this->getJson('/api/v1/member/payslips')->assertOk()->json('data.0.download_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

        // tampering with the employee scope invalidates the signature
        $this->get(str_replace('e=' . $profile->id, 'e=999', $url))->assertStatus(403);
    }

    public function test_payslips_endpoint_returns_history(): void
    {
        $member = $this->makeEmployee('PAY4');
        $profile = $member->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(1)->toDateString(), 'present');
        app(PayrollService::class)->generate($period->year, $period->month);

        Sanctum::actingAs($member, ['*']);
        $res = $this->getJson('/api/v1/member/payslips')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($period->format('Y-m'), $res->json('data.0.period'));
        $this->assertSame('pending', $res->json('data.0.status'));
    }
}
