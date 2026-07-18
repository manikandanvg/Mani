<?php

namespace App\Services\Lbox;

use App\Models\AttendanceRecord;
use App\Models\BranchAttendance;
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
        $uid = strtoupper(trim($tagUid));

        // The BRANCH's own card (held by the incharge): morning tap opens the
        // branch, evening tap closes it — no employee attendance involved.
        if ($device->branch_id && $device->branch?->rfid_tag === $uid) {
            return $this->branchCardTap($device, $lang);
        }

        $employee = EmployeeProfile::where('rfid_tag', $uid)->first();

        if (! $employee) {
            return [
                'result' => 'unknown_tag',
                'message' => $this->say($lang, 'unknown_tag'),
                'tag_uid' => $uid,   // box displays it — HQ registers the card by this number
            ];
        }
        if ($employee->status !== 'active') {
            return ['result' => 'blocked', 'message' => $this->say($lang, 'blocked')];
        }

        $name = $employee->member?->name ?? $employee->employee_code;
        $today = AttendanceRecord::where('employee_profile_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();

        if (! $today || ! $today->check_in_at) {
            $record = $this->attendance->checkIn($employee, [], null, 'device');
            $record->update(['device_id' => $device->id]);   // which box the tap happened at
            $opened = $this->openBranch($device, $employee);

            return [
                'result' => 'checked_in',
                'message' => $this->say($lang, 'checked_in', $name),
                'employee' => $name,
                'branch_opened' => $opened,   // first tap of the day — box celebrates it
            ];
        }

        if (! $today->check_out_at) {
            if ($today->check_in_at->gt(Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_S))) {
                return ['result' => 'duplicate', 'message' => $this->say($lang, 'duplicate', $name), 'employee' => $name];
            }
            $this->attendance->checkOut($employee);
            $this->closeBranch($device, $employee);

            return ['result' => 'checked_out', 'message' => $this->say($lang, 'checked_out', $name), 'employee' => $name];
        }

        return ['result' => 'day_complete', 'message' => $this->say($lang, 'day_complete', $name), 'employee' => $name];
    }

    /** The branch card: first tap of the day OPENS, later taps stamp/refresh the CLOSE. */
    protected function branchCardTap(Device $device, string $lang): array
    {
        $day = BranchAttendance::where('branch_id', $device->branch_id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();

        if (! $day || ! $day->opened_at) {
            $day ??= BranchAttendance::create([
                'branch_id' => $device->branch_id,
                'date' => Carbon::today(),
                'device_id' => $device->id,
            ]);
            $day->update(['opened_at' => Carbon::now()]);

            return [
                'result' => 'branch_opened',
                'message' => $this->say($lang, 'branch_opened'),
                'branch_opened' => true,
            ];
        }

        if ($day->opened_at->gt(Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_S))) {
            return ['result' => 'duplicate', 'message' => $this->say($lang, 'branch_duplicate')];
        }

        $day->update(['closed_at' => Carbon::now()]);

        return ['result' => 'branch_closed', 'message' => $this->say($lang, 'branch_closed')];
    }

    /** First tap of the day at this box = the branch OPENS (goes online). Returns true only for THE opening tap. */
    protected function openBranch(Device $device, EmployeeProfile $employee): bool
    {
        if (! $device->branch_id) {
            return false;
        }

        // NOT firstOrCreate: the 'date' cast stores a time component, so an
        // equality lookup on the plain date string misses the existing row
        // (second check-in of the day would then violate the unique index).
        $day = BranchAttendance::where('branch_id', $device->branch_id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first()
            ?? BranchAttendance::create([
                'branch_id' => $device->branch_id,
                'date' => Carbon::today(),
                'device_id' => $device->id,
            ]);

        if (! $day->opened_at) {
            $day->update(['opened_at' => Carbon::now(), 'opened_by' => $employee->id]);

            return true;
        }

        return false;
    }

    /** Check-out taps stamp/refresh the closing time — the LAST one wins. */
    protected function closeBranch(Device $device, EmployeeProfile $employee): void
    {
        if (! $device->branch_id) {
            return;
        }

        BranchAttendance::where('branch_id', $device->branch_id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->update(['closed_at' => Carbon::now(), 'closed_by' => $employee->id]);
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
                'branch_opened' => 'Branch opened. Have a great day!',
                'branch_closed' => 'Branch closed. Goodbye!',
                'branch_duplicate' => 'Branch is already open.',
            ],
            'ta' => [
                'checked_in' => "வணக்கம் {$name}",
                'checked_out' => "சென்று வாருங்கள் {$name}",
                'duplicate' => "ஏற்கனவே வருகை பதிவாகிவிட்டது, {$name}",
                'day_complete' => "இன்றைய வருகை முடிந்துவிட்டது, {$name}",
                'unknown_tag' => 'அட்டை அடையாளம் காணப்படவில்லை. தலைமை அலுவலகத்தைத் தொடர்பு கொள்ளவும்.',
                'blocked' => 'இந்த ஊழியர் ஊதியப் பட்டியலில் செயலில் இல்லை.',
                'branch_opened' => 'கிளை திறக்கப்பட்டது. நல்ல நாள்!',
                'branch_closed' => 'கிளை மூடப்பட்டது. சென்று வாருங்கள்!',
                'branch_duplicate' => 'கிளை ஏற்கனவே திறந்துள்ளது.',
            ],
        ];

        return $lines[$lang][$key] ?? $lines['en'][$key];
    }
}
