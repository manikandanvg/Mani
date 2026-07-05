<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Payslip;
use App\Models\Rank;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\LeaveService;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Leave workflow: app request → HQ approves as paid/unpaid (writes the attendance
 * rows payroll reads, never over worked days) or rejects.
 */
class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Member $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $stage = Rank::create([
            'code' => 'TALUK_DIRECTOR', 'name' => ['en' => 'Taluk Admin'], 'depth' => 1,
            'target_bv' => 50000, 'monthly_salary' => 20000, 'tds_pct' => 0,
        ]);
        $this->employee = Member::create([
            'member_code' => 'LEAVE1', 'name' => 'Worker', 'phone' => '9000000060',
            'joined_on' => now()->subMonths(2), 'placement' => 'level',
            'rank_id' => $stage->id, 'status' => 'active',
        ]);
        app(EmployeeService::class)->enroll($this->employee, [
            'date_of_joining' => now()->subMonths(2)->toDateString(),
        ]);
    }

    public function test_member_files_and_lists_requests_but_non_employees_are_403(): void
    {
        Sanctum::actingAs($this->employee, ['*']);

        $from = Carbon::today()->addDays(3)->toDateString();
        $to = Carbon::today()->addDays(4)->toDateString();

        $this->postJson('/api/v1/member/leave-requests', ['from' => $from, 'to' => $to, 'reason' => 'Family function'])
            ->assertStatus(201)
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.days', 2);

        $this->getJson('/api/v1/member/leave-requests')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Family function');

        // overlapping range is refused
        $this->postJson('/api/v1/member/leave-requests', ['from' => $to, 'to' => $to])
            ->assertStatus(422);

        // inverted range is refused
        $this->postJson('/api/v1/member/leave-requests', ['from' => $to, 'to' => $from])
            ->assertStatus(422);

        $base = Rank::create(['code' => 'MEMBER', 'name' => ['en' => 'Distributor'], 'depth' => 0, 'target_bv' => 0]);
        $plain = Member::create([
            'member_code' => 'LEAVE0', 'name' => 'NotStaff', 'phone' => '9000000061',
            'joined_on' => now(), 'placement' => 'level', 'rank_id' => $base->id, 'status' => 'active',
        ]);
        Sanctum::actingAs($plain, ['*']);
        $this->getJson('/api/v1/member/leave-requests')->assertStatus(403);
    }

    public function test_approval_writes_attendance_but_keeps_worked_days_and_payroll_pays_paid_leave(): void
    {
        $profile = $this->employee->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();

        // day 2 was actually worked before HQ got to the request
        app(AttendanceService::class)->markManual($profile, $period->copy()->day(2)->toDateString(), 'present');

        $leave = app(LeaveService::class)->request(
            $profile, $period->copy()->day(1)->toDateString(), $period->copy()->day(3)->toDateString(), 'Trip',
        );
        app(LeaveService::class)->approve($leave, 'paid_leave');

        $this->assertSame('approved', $leave->fresh()->status);
        $statuses = AttendanceRecord::where('employee_profile_id', $profile->id)
            ->orderBy('date')->pluck('status')->all();
        $this->assertSame(['paid_leave', 'present', 'paid_leave'], $statuses);

        // payroll counts the two paid-leave days + the worked day
        $run = app(PayrollService::class)->generate($period->year, $period->month);
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $profile->id)->firstOrFail();
        $this->assertSame(3.0, (float) $slip->payable_days);
    }

    public function test_unpaid_approval_and_rejection_do_not_pay(): void
    {
        $profile = $this->employee->employeeProfile;
        $period = Carbon::today()->subMonthNoOverflow()->startOfMonth();

        $unpaid = app(LeaveService::class)->request(
            $profile, $period->copy()->day(5)->toDateString(), $period->copy()->day(5)->toDateString(),
        );
        app(LeaveService::class)->approve($unpaid, 'leave');

        $rejected = app(LeaveService::class)->request(
            $profile, $period->copy()->day(10)->toDateString(), $period->copy()->day(11)->toDateString(),
        );
        app(LeaveService::class)->reject($rejected, null, 'Peak season');

        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('Peak season', $rejected->fresh()->admin_note);
        $this->assertSame(1, AttendanceRecord::where('employee_profile_id', $profile->id)->count()); // only the unpaid day
        $this->assertSame('leave', AttendanceRecord::where('employee_profile_id', $profile->id)->first()->status);

        $run = app(PayrollService::class)->generate($period->year, $period->month);
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_profile_id', $profile->id)->firstOrFail();
        $this->assertSame(0.0, (float) $slip->payable_days);

        // a decided request cannot be re-decided
        $this->expectException(\RuntimeException::class);
        app(LeaveService::class)->approve($rejected->fresh(), 'paid_leave');
    }
}
