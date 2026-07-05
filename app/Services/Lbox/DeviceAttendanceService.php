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
 * Messages are composed in the DEVICE's language (en/ta) — the box speaks them.
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
        $lang = $device->language ?? 'en';
        $employee = EmployeeProfile::where('rfid_tag', strtoupper(trim($tagUid)))->first();

        if (! $employee) {
            return ['result' => 'unknown_tag', 'message' => $this->say($lang, 'unknown_tag')];
        }
        if ($employee->status !== 'active') {
            return ['result' => 'blocked', 'message' => $this->say($lang, 'blocked')];
        }

        $name = $employee->member?->name ?? $employee->employee_code;
        $today = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();

        if (! $today || ! $today->check_in_at) {
            $this->attendance->checkIn($employee, [], null, 'device');

            return ['result' => 'checked_in', 'message' => $this->say($lang, 'checked_in', $name), 'employee' => $name];
        }

        if (! $today->check_out_at) {
            if ($today->check_in_at->gt(Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_S))) {
                return ['result' => 'duplicate', 'message' => $this->say($lang, 'duplicate', $name), 'employee' => $name];
            }
            $this->attendance->checkOut($employee);

            return ['result' => 'checked_out', 'message' => $this->say($lang, 'checked_out', $name), 'employee' => $name];
        }

        return ['result' => 'day_complete', 'message' => $this->say($lang, 'day_complete', $name), 'employee' => $name];
    }

    protected function say(string $lang, string $key, string $name = ''): string
    {
        $lines = [
            'en' => [
                'checked_in' => "Welcome {$name}",
                'checked_out' => "Goodbye {$name}",
                'duplicate' => "Already checked in, {$name}",
                'day_complete' => "Attendance already complete, {$name}",
                'unknown_tag' => 'Card not recognised. Contact Head Office.',
                'blocked' => 'This employee is not active on the payroll.',
            ],
            'ta' => [
                'checked_in' => "வணக்கம் {$name}",
                'checked_out' => "சென்று வாருங்கள் {$name}",
                'duplicate' => "ஏற்கனவே வருகை பதிவாகிவிட்டது, {$name}",
                'day_complete' => "இன்றைய வருகை முடிந்துவிட்டது, {$name}",
                'unknown_tag' => 'அட்டை அடையாளம் காணப்படவில்லை. தலைமை அலுவலகத்தைத் தொடர்பு கொள்ளவும்.',
                'blocked' => 'இந்த ஊழியர் ஊதியப் பட்டியலில் செயலில் இல்லை.',
            ],
        ];

        return $lines[$lang][$key] ?? $lines['en'][$key];
    }
}
