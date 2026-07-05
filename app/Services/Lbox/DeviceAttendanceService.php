<?php

namespace App\Services\Lbox;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\EmployeeProfile;
use App\Services\Payroll\AttendanceService;
use Illuminate\Support\Carbon;

/**
 * RFID tap at the box → the SAME attendance ledger payroll reads (source 'device').
 * Tap semantics: first tap of the day checks in, a later tap checks out; taps inside
 * the duplicate window (accidental double-tap / offline resend) are ignored.
 */
class DeviceAttendanceService
{
    public const DUPLICATE_WINDOW_S = 120;

    public function __construct(protected AttendanceService $attendance)
    {
    }

    /**
     * @return array{result: string, message: string, employee?: string}
     */
    public function tap(Device $device, string $tagUid): array
    {
        $employee = EmployeeProfile::where('rfid_tag', strtoupper(trim($tagUid)))->first();

        if (! $employee) {
            return ['result' => 'unknown_tag', 'message' => 'Card not recognised. Contact Head Office.'];
        }
        if ($employee->status !== 'active') {
            return ['result' => 'blocked', 'message' => 'This employee is not active on the payroll.'];
        }

        $name = $employee->member?->name ?? $employee->employee_code;
        $today = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();

        if (! $today || ! $today->check_in_at) {
            $this->attendance->checkIn($employee, [], null, 'device');

            return ['result' => 'checked_in', 'message' => "Welcome {$name}", 'employee' => $name];
        }

        if (! $today->check_out_at) {
            if ($today->check_in_at->gt(Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_S))) {
                return ['result' => 'duplicate', 'message' => "Already checked in, {$name}", 'employee' => $name];
            }
            $this->attendance->checkOut($employee);

            return ['result' => 'checked_out', 'message' => "Goodbye {$name}", 'employee' => $name];
        }

        return ['result' => 'day_complete', 'message' => "Attendance already complete, {$name}", 'employee' => $name];
    }
}
