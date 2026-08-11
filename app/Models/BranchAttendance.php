<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One branch-day: opened when the first RFID card touches the branch's L-BOX
 * in the morning, closing time stamped by check-out taps. A branch with no row
 * (or no opened_at) today is OFFLINE.
 */
class BranchAttendance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Branch open / close alerts (board 2026-08-11): model-level so BOTH tap
        // paths (employee card + branch card) notify HQ admins and the branch's own
        // dealer. Close taps refresh closed_at all day — only the FIRST stamp
        // notifies (original was null); later refreshes stay silent.
        static::updated(function (self $day) {
            $branch = $day->branch;
            if (! $branch) {
                return;
            }
            $dealer = $branch->distributorUser?->memberAccount;

            if ($day->wasChanged('opened_at') && $day->opened_at && $day->getOriginal('opened_at') === null) {
                $when = $day->opened_at->format('h:i A');
                \App\Services\Push\Notifier::admins(
                    'Branch OPEN — ' . $branch->name,
                    'Opened at ' . $when . ' via RFID tap. The branch is online for the day.',
                );
                \App\Services\Push\Notifier::to($dealer, 'system',
                    'Your branch is open',
                    $branch->name . ' opened at ' . $when . '. Business systems are live for the day.',
                );
            }

            if ($day->wasChanged('closed_at') && $day->closed_at && $day->getOriginal('closed_at') === null) {
                $when = $day->closed_at->format('h:i A');
                \App\Services\Push\Notifier::admins(
                    'Branch CLOSED — ' . $branch->name,
                    'Closing stamped at ' . $when . ' via RFID tap.',
                );
                \App\Services\Push\Notifier::to($dealer, 'system',
                    'Your branch is closed',
                    $branch->name . ' closed at ' . $when . '. See you tomorrow!',
                );
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'closed_by');
    }

    /** Is this branch open (online) today? */
    public static function isOpenToday(int $branchId): bool
    {
        return static::where('branch_id', $branchId)
            ->whereDate('date', Carbon::today()->toDateString())
            ->whereNotNull('opened_at')
            ->exists();
    }
}
