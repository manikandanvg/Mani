<?php

namespace App\Services\Payroll;

use App\Models\EmployeeProfile;
use App\Models\Member;
use App\Support\Translatable;
use Illuminate\Support\Carbon;

/**
 * Enrols ranked distributors onto the payroll (2026-07 board mandate). A member
 * holding a TBP stage (rank depth ≥ 1) becomes an employee: designation and
 * salary/TDS defaults are copied from the stage, overridable per profile.
 */
class EmployeeService
{
    /** Enrol one member. Idempotent — returns the existing profile if already enrolled. */
    public function enroll(Member $member, array $overrides = []): EmployeeProfile
    {
        $existing = EmployeeProfile::withTrashed()->where('member_id', $member->id)->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $member->loadMissing('rank');
        $rank = $member->rank;

        if (! $rank || (int) $rank->depth < 1) {
            throw new \InvalidArgumentException('Only distributors holding a TBP stage can be enrolled as employees.');
        }

        return EmployeeProfile::create(array_merge([
            'member_id' => $member->id,
            'employee_code' => 'EMP-' . $member->member_code,
            'designation' => Translatable::pick($rank->name) ?: $rank->code,
            'rank_id' => $rank->id,
            'monthly_salary' => (float) $rank->monthly_salary,
            'date_of_joining' => Carbon::today()->toDateString(),
            'status' => 'active',
        ], $overrides));
    }

    /**
     * Auto-enrol hook fired on rank promotion. Quiet variant of enroll(): only ever
     * CREATES a profile — a soft-deleted one stays deleted (HQ removed that employee
     * deliberately; a re-promotion must not silently rehire them).
     */
    public function autoEnroll(int $memberId): ?EmployeeProfile
    {
        if (EmployeeProfile::withTrashed()->where('member_id', $memberId)->exists()) {
            return null;
        }

        $member = Member::with('rank')->find($memberId);
        if (! $member || $member->status !== 'active' || ! $member->rank || (int) $member->rank->depth < 1) {
            return null;
        }

        return $this->enroll($member);
    }

    /**
     * Enrol every active member holding a TBP stage who has never been an employee.
     * Returns the number of new enrolments. Existing profiles are left untouched
     * (salary overrides survive re-syncs) and removed employees stay removed.
     */
    public function syncFromRanks(): int
    {
        $eligible = Member::query()
            ->where('status', 'active')
            ->whereHas('rank', fn ($q) => $q->where('depth', '>=', 1))
            ->whereDoesntHave('employeeProfile', fn ($q) => $q->withTrashed())
            ->with('rank')
            ->get();

        $count = 0;
        foreach ($eligible as $member) {
            $this->enroll($member);
            $count++;
        }

        return $count;
    }
}
