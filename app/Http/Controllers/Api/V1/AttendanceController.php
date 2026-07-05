<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Payslip;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\LeaveService;
use App\Services\Payroll\PayslipPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Employee attendance + payslips for the app ("Jeweller Plan" model): check-in with
 * a front-camera selfie + GPS fix, check-out, month view, payslip history. Only
 * distributors enrolled on the payroll (employee profile) may use these — others 403.
 */
class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendance)
    {
    }

    /** GET /member/attendance?month=YYYY-MM — today's state + the month's records. */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $month = $request->query('month');
        $start = $month ? Carbon::createFromFormat('Y-m', $month)->startOfMonth() : Carbon::today()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->get();

        $today = $records->firstWhere('date', Carbon::today()->startOfDay());

        return response()->json([
            'employee' => [
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation,
                'date_of_joining' => optional($employee->date_of_joining)->toDateString(),
                'status' => $employee->status,
            ],
            'month' => $start->format('Y-m'),
            'today' => $today ? $this->present($today) : null,
            'summary' => [
                'present' => $records->where('status', 'present')->count(),
                'half_day' => $records->where('status', 'half_day')->count(),
                'paid_leave' => $records->where('status', 'paid_leave')->count(),
                'leave' => $records->where('status', 'leave')->count(),
                'absent' => $records->where('status', 'absent')->count(),
            ],
            'records' => $records->map(fn (AttendanceRecord $r) => $this->present($r))->values(),
        ]);
    }

    /** POST /member/attendance/check-in — multipart: selfie (image, required), lat, lng, accuracy. */
    public function checkIn(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'selfie' => ['required', 'image', 'max:8192'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $record = $this->attendance->checkIn($employee, $data, $request->file('selfie'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'record' => $this->present($record)], 201);
    }

    /** POST /member/attendance/check-out — selfie optional on the way out. */
    public function checkOut(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'selfie' => ['nullable', 'image', 'max:8192'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $record = $this->attendance->checkOut($employee, $data, $request->file('selfie'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'record' => $this->present($record)]);
    }

    /** GET /member/leave-requests — the employee's requests, newest first. */
    public function leaves(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $requests = LeaveRequest::where('employee_profile_id', $employee->id)
            ->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'data' => $requests->map(fn (LeaveRequest $r) => $this->presentLeave($r)),
        ]);
    }

    /** POST /member/leave-requests — from, to (Y-m-d), reason. HQ decides paid vs unpaid. */
    public function storeLeave(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $leave = app(LeaveService::class)->request($employee, $data['from'], $data['to'], $data['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'request' => $this->presentLeave($leave)], 201);
    }

    /** GET /member/payslips — newest first, each with a short-lived signed PDF link. */
    public function payslips(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $slips = Payslip::where('employee_profile_id', $employee->id)
            ->with('run')
            ->get()
            ->sortByDesc(fn (Payslip $s) => [$s->run->period_year, $s->run->period_month])
            ->values();

        return response()->json([
            'data' => $slips->map(fn (Payslip $s) => [
                'id' => $s->id,
                'period' => $s->run->periodLabel(),
                'run_status' => $s->run->status,
                'days_present' => (float) $s->days_present,
                'payable_days' => (float) $s->payable_days,
                'gross' => (float) $s->gross,
                'pf' => (float) $s->pf_employee,
                'esi' => (float) $s->esi_employee,
                'tds' => (float) $s->tds,
                'net' => (float) $s->net,
                'status' => $s->status,
                'paid_at' => optional($s->paid_at)->toDateString(),
                'download_url' => URL::temporarySignedRoute(
                    'api.member.payslip',
                    now()->addMinutes(15),
                    ['id' => $s->id, 'e' => $employee->id],
                ),
            ]),
        ]);
    }

    /** GET /member/payslips/{id}/pdf (signed) — stream the PDF, scoped to the employee. */
    public function payslipPdf(Request $request, int $id)
    {
        $payslip = Payslip::where('id', $id)
            ->where('employee_profile_id', (int) $request->query('e'))
            ->firstOrFail();

        return app(PayslipPdf::class)->stream($payslip);
    }

    protected function presentLeave(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'from' => $r->start_date->toDateString(),
            'to' => $r->end_date->toDateString(),
            'days' => $r->days(),
            'reason' => $r->reason,
            'status' => $r->status,
            'approved_type' => $r->approved_type,
            'admin_note' => $r->admin_note,
            'requested_at' => $r->created_at->toIso8601String(),
        ];
    }

    protected function present(AttendanceRecord $r): array
    {
        return [
            'date' => optional($r->date)->toDateString(),
            'status' => $r->status,
            'check_in_at' => optional($r->check_in_at)->toIso8601String(),
            'check_out_at' => optional($r->check_out_at)->toIso8601String(),
            'source' => $r->source,
            'note' => $r->note,
        ];
    }

    /** Resolve the authenticated member's ACTIVE employee profile, or 403. */
    protected function employee(Request $request): EmployeeProfile
    {
        $user = $request->user();
        abort_unless($user instanceof Member, 403, 'This area is for distributors.');

        $employee = $user->employeeProfile;
        abort_unless($employee && $employee->status === 'active', 403, 'You are not enrolled on the payroll.');

        return $employee;
    }
}
