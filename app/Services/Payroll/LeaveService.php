<?php

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Leave workflow: employee requests a date range from the app, HQ approves it as
 * PAID or UNPAID leave (or rejects). Approval writes the day-by-day attendance
 * rows the payroll run reads; days that already carry a real check-in are kept.
 */
class LeaveService
{
    public const MAX_DAYS = 31;

    /** File a pending request. Throws InvalidArgumentException on bad ranges/overlaps. */
    public function request(EmployeeProfile $employee, string $from, string $to, ?string $reason = null): LeaveRequest
    {
        if ($employee->status !== 'active') {
            throw new \InvalidArgumentException('Employee profile is not active.');
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('The end date must be on or after the start date.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new \InvalidArgumentException('A single request may cover at most ' . self::MAX_DAYS . ' days.');
        }

        $overlaps = LeaveRequest::where('employee_profile_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlaps) {
            throw new \InvalidArgumentException('These dates overlap another leave request of yours.');
        }

        return LeaveRequest::create([
            'employee_profile_id' => $employee->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    /**
     * Approve as paid_leave or (unpaid) leave and write the attendance rows.
     * Days that already have a check-in or an explicit manual status keep it —
     * leave only fills empty/absent days, so it can never erase worked time.
     */
    public function approve(LeaveRequest $request, string $type, ?int $userId = null, ?string $note = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be approved.');
        }
        if (! in_array($type, ['paid_leave', 'leave'], true)) {
            throw new \InvalidArgumentException("Leave must be approved as paid_leave or leave, [{$type}] given.");
        }

        $request->update([
            'status' => 'approved',
            'approved_type' => $type,
            'decided_by' => $userId,
            'decided_at' => Carbon::now(),
            'admin_note' => $note,
        ]);

        $existing = AttendanceRecord::where('employee_profile_id', $request->employee_profile_id)
            ->whereDate('date', '>=', $request->start_date->toDateString())
            ->whereDate('date', '<=', $request->end_date->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => $r->date->toDateString());

        for ($day = $request->start_date->copy(); $day->lte($request->end_date); $day->addDay()) {
            $record = $existing->get($day->toDateString());
            if ($record && ($record->check_in_at || $record->status !== 'absent')) {
                continue;
            }

            AttendanceRecord::updateOrCreate(
                ['employee_profile_id' => $request->employee_profile_id, 'date' => $day->toDateString()],
                [
                    'status' => $type,
                    'source' => 'manual',
                    'note' => trim('Leave request #' . $request->id . ($request->reason ? " — {$request->reason}" : '')),
                    'marked_by' => $userId,
                ],
            );
        }

        return $request;
    }

    /** Reject a pending request — no attendance is written. */
    public function reject(LeaveRequest $request, ?int $userId = null, ?string $note = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be rejected.');
        }

        $request->update([
            'status' => 'rejected',
            'decided_by' => $userId,
            'decided_at' => Carbon::now(),
            'admin_note' => $note,
        ]);

        return $request;
    }
}
