<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the expense register (board 2026-11-17): money Head Office spends outside the
 * system's own payouts — the award handed to a distributor who reached a TBP stage (car, tour),
 * the monthly office rent, salaries outside payroll, electricity and the like. Submitted with
 * its bill, approved by the owner; only APPROVED rows reach the auditor's Balance Sheet /
 * Profit & Loss (NextAccountsSource::expenses).
 */
class Expense extends Model
{
    use \App\Models\Concerns\LogsActivity;

    public const ACTIVITY_CATEGORY = 'accounts';

    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** category key => [label, Profit & Loss line of the auditor workbook]. */
    public const CATEGORIES = [
        'tbp_reward' => ['TBP Reward / Award (car, tour, gift)', 'rewards'],
        'office_rent' => ['Office Rent', 'rent'],
        'salary' => ['Salary / Wages (outside payroll)', 'salaries'],
        'electricity' => ['Electricity', 'electricity'],
        'bank_charges' => ['Bank Charges', 'bank_charges'],
        'marketing' => ['Marketing / Advertisement / Events', 'marketing'],
        'insurance' => ['Insurance', 'insurance'],
        'repairs' => ['Repairs & Maintenance', 'repairs'],
        'professional_fees' => ['Professional / Audit / Legal Fees', 'professional_fees'],
        'travel' => ['Travel & Conveyance', 'other_expenses'],
        'office' => ['Office / Printing / Stationery', 'other_expenses'],
        'other' => ['Other Expenses', 'other_expenses'],
    ];

    public const MODES = ['cash' => 'Cash', 'bank' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'card' => 'Card'];

    protected $guarded = [];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'attachments' => 'array',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $e) {
            $e->expense_no = $e->expense_no ?: static::nextNo();
        });
    }

    public static function nextNo(): string
    {
        $n = (int) static::max('id') + 1;
        do {
            $no = 'EXP-' . str_pad((string) $n++, 6, '0', STR_PAD_LEFT);
        } while (static::where('expense_no', $no)->exists());

        return $no;
    }

    /** @return array<string, string> key => label */
    public static function categoryOptions(): array
    {
        return array_map(fn (array $c) => $c[0], self::CATEGORIES);
    }

    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key][0] ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** The auditor workbook's Profit & Loss line this expense falls under. */
    public function ledgerLine(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'other_expenses';
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function rank() { return $this->belongsTo(Rank::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
