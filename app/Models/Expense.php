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
        'meeting' => ['Meetings (weekly / monthly)', 'marketing'],
        'food' => ['Food & Beverages (office)', 'other_expenses'],
        'travel' => ['Travel & Conveyance', 'other_expenses'],
        'fuel' => ['Fuel', 'other_expenses'],
        'office' => ['Office / Printing / Stationery', 'other_expenses'],
        'other' => ['Other Expenses', 'other_expenses'],
    ];

    public const KIND_EXPENSE = 'expense';
    public const KIND_ASSET = 'asset';

    /**
     * Capital assets (board 2026-11-27): bought once, shown on the Balance Sheet under Fixed
     * Assets and never in the Profit & Loss. category key => [label, Balance Sheet line].
     */
    public const ASSET_CATEGORIES = [
        'asset_computers' => ['Computers & Peripherals', 'computers'],
        'asset_interior' => ['Interior', 'showroom_interior'],
        'asset_machinery' => ['Factory Machinery & Tools', 'plant_machinery'],
        'asset_vehicles' => ['Vehicles', 'vehicles'],
        'asset_furniture' => ['Furniture & Fixtures', 'furniture'],
        'asset_land_building' => ['Land & Building', 'land_building'],
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
        // the kind follows the category, whatever screen or command saved the row
        static::saving(function (Expense $e) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('expenses', 'kind')) {
                $e->kind = $e->isAsset() ? self::KIND_ASSET : self::KIND_EXPENSE;
            }
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
        return array_map(fn (array $c) => $c[0], self::CATEGORIES + self::ASSET_CATEGORIES);
    }

    /** The same list in two groups, for the category picker. */
    public static function groupedCategoryOptions(): array
    {
        return [
            'Expenses (Profit & Loss)' => array_map(fn (array $c) => $c[0], self::CATEGORIES),
            'Capital assets (Balance Sheet)' => array_map(fn (array $c) => $c[0], self::ASSET_CATEGORIES),
        ];
    }

    public function isAsset(): bool
    {
        return isset(self::ASSET_CATEGORIES[$this->category]);
    }

    /**
     * Approved capital assets bought up to a date, by Balance Sheet line.
     *
     * @return array<string, float>
     */
    public static function assetTotals(\Illuminate\Support\Carbon $asOf): array
    {
        $out = [];
        $rows = static::where('status', self::APPROVED)->whereIn('category', array_keys(self::ASSET_CATEGORIES))
            ->whereDate('expense_date', '<=', $asOf->toDateString())
            ->selectRaw('category, SUM(amount) AS total')->groupBy('category')->pluck('total', 'category');
        foreach ($rows as $category => $total) {
            $line = self::ASSET_CATEGORIES[$category][1];
            $out[$line] = ($out[$line] ?? 0) + (float) $total;
        }

        return $out;
    }

    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key][0] ?? self::ASSET_CATEGORIES[$key][0] ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** The auditor workbook's Profit & Loss line this expense falls under. */
    public function ledgerLine(): string
    {
        return self::CATEGORIES[$this->category][1] ?? self::ASSET_CATEGORIES[$this->category][1] ?? 'other_expenses';
    }

    public function member() { return $this->belongsTo(Member::class); }
    public function rank() { return $this->belongsTo(Rank::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
