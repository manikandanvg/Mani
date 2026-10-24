<?php

namespace App\Support\Accounts;

use App\Models\Setting;

/**
 * The balance-sheet lines neither book tracks (capital, loans, fixed assets, bank,
 * expenses, tax). HQ keys them in on the Balance Sheet Report page and they are kept
 * per financial year in the settings table (group "accounts", key "<fy>.<line>").
 */
final class ManualLedger
{
    public const GROUP = 'accounts';

    /** section label → [field => line label]. Order = order on the form. */
    public const FIELDS = [
        'Capital & loans' => [
            'opening_capital' => 'Opening Capital',
            'drawings' => 'Drawings',
            'bank_gold_loan' => 'Bank / Gold Loan',
            'working_capital_loan' => 'Working Capital Loan',
            'directors_loan' => 'Directors / Partners Loan',
            'provisions_payable' => 'Provision / Expenses Payable',
        ],
        'Fixed assets' => [
            'land_building' => 'Land & Building',
            'furniture' => 'Furniture & Fixtures',
            'showroom_interior' => 'Showroom Interior',
            'computers' => 'Computers & Equipment',
            'vehicles' => 'Vehicles',
            'accumulated_depreciation' => 'Accumulated Depreciation',
        ],
        'Other stock & current assets' => [
            'diamond_stock' => 'Diamond Jewellery Stock',
            'precious_stones' => 'Precious Stones',
            'wip_stock' => 'WIP / Manufacturing Stock',
            'old_gold_stock' => 'Old Gold Stock',
            'bank_balance' => 'Bank Balance',
            'other_current_assets' => 'Other Current Assets',
        ],
        'Profit & Loss lines outside the system' => [
            'old_gold_sales' => 'Old Gold / Exchange Sales',
            'other_revenue' => 'Other Operating Revenue',
            'diamond_purchases' => 'Purchases – Diamond / Stones',
            'manufacturing_charges' => 'Goldsmith / Manufacturing Charges',
            'freight_inward' => 'Freight / Carriage Inward',
            'salaries_extra' => 'Employee Salaries (outside payroll)',
            'rent' => 'Rent & Maintenance',
            'electricity' => 'Electricity',
            'bank_charges' => 'Bank Charges',
            'marketing' => 'Marketing / Advertisement',
            'insurance' => 'Insurance',
            'repairs' => 'Repairs & Maintenance',
            'depreciation' => 'Depreciation',
            'professional_fees' => 'Professional / Audit Fees',
            'other_expenses' => 'Other Expenses',
            'income_tax' => 'Income Tax / Current Tax',
        ],
    ];

    /** @return array<string, string> field => label, flat. */
    public static function fields(): array
    {
        return array_merge(...array_values(self::FIELDS));
    }

    /** @return array<string, float> every field, 0 when never saved. */
    public static function load(string $fy): array
    {
        $saved = Setting::where('group', self::GROUP)->where('key', 'like', $fy.'.%')->pluck('value', 'key');
        $out = [];
        foreach (array_keys(self::fields()) as $field) {
            $out[$field] = (float) ($saved[$fy.'.'.$field] ?? 0);
        }

        return $out;
    }

    public static function save(string $fy, array $values): void
    {
        foreach (array_keys(self::fields()) as $field) {
            Setting::updateOrCreate(
                ['group' => self::GROUP, 'key' => $fy.'.'.$field],
                ['value' => (string) round((float) ($values[$field] ?? 0), 2), 'type' => 'float'],
            );
        }
    }
}
