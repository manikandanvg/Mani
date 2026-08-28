<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Monthly-task catalogue (board 2026-08-28). Auto types are measured by
 * App\Services\Tasks\TaskEngine from ledgers the system already keeps; manual
 * types are proven by the person (task_submissions) and verified by HQ.
 */
class TaskType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'default_target' => 'decimal:2',
        'default_per_day' => 'decimal:2',
        'default_weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public const SCOPES = ['employee' => 'Employee (TBP stage)', 'branch' => 'Branch (level)'];
    public const MODES = ['auto' => 'Measured automatically', 'manual' => 'Proof submitted, HQ verifies'];
    public const UNITS = ['count' => 'Count', 'amount' => '₹ amount', 'days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes'];

    /**
     * The catalogue the board approved (2026-08-29). Seeded on demand — running the
     * engine or opening the Task Types screen adds any missing row, never overwrites edits.
     */
    public const DEFAULTS = [
        // ── Branch ──
        ['key' => 'OPEN_HOURS', 'scope' => 'branch', 'unit' => 'days', 'default_target' => 26, 'default_per_day' => 8, 'name' => 'Branch open (hours per day)', 'description' => 'Days the branch stayed open at least the set hours, L-BOX present (RFID open/close taps; auto-closed at 8 PM).'],
        ['key' => 'OPEN_DAYS', 'scope' => 'branch', 'unit' => 'days', 'default_target' => 26, 'name' => 'Days opened', 'description' => 'Days with an opening tap at the L-BOX.'],
        ['key' => 'STOCK_KEPT', 'scope' => 'branch', 'unit' => 'days', 'direction' => 'down', 'default_target' => 0, 'name' => 'Opening stock kept', 'description' => 'Shortfall days — days on which any metal item was below its Opening level at the 8 PM snapshot. Target = allowed shortfall days.'],
        ['key' => 'RD_RENEWALS', 'scope' => 'branch', 'unit' => 'count', 'default_target' => 20, 'name' => 'RD renewals collected', 'description' => 'RD collections recorded at the branch in the month.'],
        ['key' => 'BILLING', 'scope' => 'branch', 'unit' => 'amount', 'default_target' => 500000, 'name' => 'Billing (₹)', 'description' => 'Net value of the branch\'s sales invoices in the month.'],
        ['key' => 'BILLING_G10', 'scope' => 'branch', 'unit' => 'amount', 'default_target' => 200000, 'name' => 'Billing through G10 (₹)', 'description' => 'Sales invoices on gold / silver purchase plans (G10), customized orders included.'],
        // ── Employee ──
        ['key' => 'ATTENDANCE', 'scope' => 'employee', 'unit' => 'days', 'default_target' => 24, 'name' => 'Daily attendance', 'description' => 'Days with both a check-in and a check-out (app selfie or L-BOX tap).'],
        ['key' => 'BRANCH_VISITS', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 4, 'name' => 'Branch visits (RFID scan)', 'description' => 'Distinct branch-days with an RFID tap at another branch\'s L-BOX.'],
        ['key' => 'ZOOM_INVITED', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 0, 'default_weight' => 0, 'name' => 'Zoom meetings invited', 'description' => 'Information only (weight 0): meetings in the month whose audience included the person.'],
        ['key' => 'ZOOM_JOINED', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 2, 'name' => 'Zoom meetings joined (verified)', 'description' => 'Meetings where Zoom\'s report shows the person for at least 50% of the scheduled duration.'],
        ['key' => 'ZOOM_MINUTES', 'scope' => 'employee', 'unit' => 'minutes', 'default_target' => 120, 'name' => 'Zoom minutes attended', 'description' => 'Total verified minutes across the month\'s Zoom meetings.'],
        ['key' => 'GENERAL_MEETINGS', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 1, 'name' => 'L-BOX general meetings attended', 'description' => 'In-person meetings (Live Meetings → platform L-BOX) where the person tapped the arena box on the day.'],
        ['key' => 'DIRECT_NEW', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 2, 'name' => 'Direct new members', 'description' => 'Members joined in the month with this person as upline.'],
        ['key' => 'GBV_GROWTH', 'scope' => 'employee', 'unit' => 'amount', 'default_target' => 100000, 'name' => 'GBV growth', 'description' => 'Group business volume added in the month (GBV now − GBV on the 1st).'],
        ['key' => 'MEET_PERSON', 'scope' => 'employee', 'unit' => 'count', 'default_target' => 2, 'name' => 'Meet a person (photo post)', 'description' => 'Community posts with a photo made by the person in the month (the meeting photo goes to Social Posts in the app).'],
        ['key' => 'TOWN_VISIT', 'scope' => 'employee', 'mode' => 'manual', 'unit' => 'count', 'default_target' => 1, 'name' => 'Town / location visit report', 'description' => 'Visit details submitted from the app (place, purpose, GPS, photo) and verified by HQ.'],
        ['key' => 'CUSTOM', 'scope' => 'employee', 'mode' => 'manual', 'unit' => 'count', 'default_target' => 1, 'default_weight' => 1, 'name' => 'Custom task', 'description' => 'Any task HQ types in when assigning; the person marks it done with proof, HQ verifies.'],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            static::firstOrCreate(['key' => $row['key']], $row + ['mode' => 'auto', 'direction' => 'up', 'default_weight' => 1, 'sort' => ($i + 1) * 10]);
        }
    }

    public function isManual(): bool
    {
        return $this->mode === 'manual';
    }

    public function targets() { return $this->hasMany(TaskTarget::class); }
    public function assignments() { return $this->hasMany(TaskAssignment::class); }

    /** Human unit for a value ("26 days", "₹5,00,000", "120 min"). */
    public function format(float $value): string
    {
        return match ($this->unit) {
            'amount' => '₹' . \App\Support\Money::group($value, 0),
            'hours' => rtrim(rtrim(number_format($value, 1), '0'), '.') . ' h',
            'minutes' => number_format($value, 0) . ' min',
            'days' => number_format($value, 0) . ' ' . ($value == 1 ? 'day' : 'days'),
            default => number_format($value, 0),
        };
    }
}
