<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Imports the board-approved scheme sheet (Scheme_new.csv, 2026-07-28) — plan
 * schema v2. Upserts by code so existing plan ids (and every FK pointing at them)
 * survive; note the sheet reassigns some codes vs legacy (P210 is now Area
 * Distributor, P211 is G10 Silver, P204 District, P203 Taluka).
 */
class PlanSchemaV2Seeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            Plan::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    protected function rows(): array
    {
        $dealerIc = ['2.5', '0.75', '0.25', '0.25', '0.25', '0.20', '0.20', '0.20', '0.20', '0.20'];
        $dealerLevel = ['0.5', '0.20', '0.10', '0.10', '0.10'];
        $plusIc = ['10.0', '3.0', '2.0', '1.5', '0.75', '0.75', '0.5', '0.5', '0.5', '0.5'];
        $g5Ic = ['5.00', '1.50', '0.50', '0.50', '0.50', '0.50', '0.40', '0.40', '0.40', '0.40'];
        $g5Level = ['1.25', '0.50', '0.25', '0.25', '0.25'];
        $zeroLevel = ['0.00', '0.00', '0.00'];

        $dealership = [
            'plan_type' => 2, 'type' => 'digital',
            'allocation_bv' => 20, 'allocation_cont' => 80,
            'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
            'counts_for_rank' => true, 'rank_factor' => 20,
            'validity_months' => 24, 'cbc_value' => 2, 'cbc_count' => 24,
            'level_depth' => 5, 'ic_schedule' => $dealerIc, 'level_schedule' => $dealerLevel,
            'level_com_duration' => 24, 'billing_margin' => 0.25, 'renewal_margin' => 0,
            'epin_count' => 1, 'is_active' => true, 'useraccess' => true,
            'settlement_cycle_months' => 24,   // manual withdraw/renewal decision at maturity
        ];

        return [
            $dealership + ['code' => 'P214', 'hid' => 1, 'name' => ['en' => 'Regional Dealership'],
                'min_value' => 50000000, 'mou_id' => 5, 'settlement' => '24 month after contract renewal'],
            $dealership + ['code' => 'P207', 'hid' => 2, 'name' => ['en' => 'Zonal Dealership'],
                'min_value' => 10000000, 'mou_id' => 5, 'settlement' => '24 month after contract renewal'],
            $dealership + ['code' => 'P204', 'hid' => 3, 'name' => ['en' => 'District Dealership'],
                'min_value' => 2500000, 'mou_id' => 5, 'settlement' => '24 month after contract renewal with opening stock'],
            $dealership + ['code' => 'P203', 'hid' => 4, 'name' => ['en' => 'Taluka Dealership'],
                'min_value' => 500000, 'mou_id' => 4, 'settlement' => '24 month after bv renewal with opening stock'],

            ['code' => 'P213', 'hid' => 5, 'name' => ['en' => 'Sub Dealer'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 20000, 'max_sales_value' => 4500000,
                'allocation_bv' => 75, 'allocation_cont' => 25,
                'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
                'counts_for_rank' => true, 'rank_factor' => 75,
                'validity_months' => 24, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 5,
                'ic_schedule' => ['6.00', '1.80', '0.60', '0.60', '0.60', '0.48', '0.48', '0.48', '0.48', '0.48'],
                'level_schedule' => ['2.0', '0.80', '0.40', '0.40', '0.40'],
                'level_com_duration' => 24, 'billing_margin' => 1.5, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 8, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_qr_pct' => 80,
                'settlement' => '12 month once contract amount 80% gold qr'],

            ['code' => 'P201', 'hid' => 6, 'name' => ['en' => 'G5 Retailer Plan'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 100000, 'max_sales_value' => 1500000,
                'allocation_bv' => 50, 'allocation_cont' => 50,
                'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
                'counts_for_rank' => true, 'rank_factor' => 50,
                'validity_months' => 24, 'cbc_value' => 6, 'cbc_count' => 24, 'level_depth' => 5,
                'ic_schedule' => $g5Ic, 'level_schedule' => $g5Level,
                'level_com_duration' => 24, 'billing_margin' => 1, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 2, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_qr_pct' => 70,
                'settlement' => '12 month once contract amount 70% gold qr'],

            ['code' => 'P205', 'hid' => 7, 'name' => ['en' => 'G24 Wholesaler Plan'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 300000, 'max_sales_value' => 3000000,
                'allocation_bv' => 30, 'allocation_cont' => 70,
                'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
                'counts_for_rank' => true, 'rank_factor' => 30,
                'validity_months' => 24, 'cbc_value' => 4, 'cbc_count' => 24, 'level_depth' => 0,
                'ic_schedule' => ['3.50', '1.25', '0.50', '0.50', '0.25', '0.20', '0.20', '0.20', '0.20', '0.20'],
                'level_schedule' => ['0.625', '0.250', '0.125', '0.125', '0.125'],
                'level_com_duration' => 24, 'billing_margin' => 0.5, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 6, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_qr_pct' => 80,
                'settlement' => '12 month closing 72000 Rs 80% gold QR'],

            ['code' => 'P210', 'hid' => 8, 'name' => ['en' => 'Area Distributor'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 15000, 'max_value' => 100000, 'max_sales_value' => 150000,
                'allocation_bv' => 10, 'allocation_cont' => 90,
                'is_redeem' => false, 'is_contract' => true, 'is_invoice' => false,
                'counts_for_rank' => true, 'rank_factor' => 10,
                'validity_months' => 12, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 0,
                'ic_schedule' => ['0', '0', '0', '0', '0', '0', '0', '0', '0', '0'],
                'level_schedule' => $zeroLevel,
                'level_com_duration' => 12, 'billing_margin' => 5, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 6, 'is_active' => true, 'useraccess' => true,
                'settlement_cycle_months' => 12, 'settlement_close' => true, 'settlement_suspend' => true,
                'settlement' => '12 month closing+useraccess close'],

            ['code' => 'P206', 'hid' => null, 'name' => ['en' => 'G10 Gold Purchase Plan'],
                'plan_type' => 1, 'type' => 'gold', 'min_value' => 1500,
                'allocation_bv' => 0, 'allocation_cont' => 0,
                'is_redeem' => true, 'is_contract' => false, 'is_invoice' => true,
                'counts_for_rank' => false, 'rank_factor' => 0,
                'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 5,
                'ic_schedule' => $dealerIc, 'level_schedule' => $zeroLevel,
                'level_com_duration' => 0, 'billing_margin' => 0, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => null, 'is_active' => true, 'useraccess' => false,
                'settlement' => null],

            ['code' => 'P211', 'hid' => null, 'name' => ['en' => 'G10 Silver Purchase Plan'],
                'plan_type' => 4, 'type' => 'silver', 'min_value' => 1500,
                'allocation_bv' => 0, 'allocation_cont' => 0,
                'is_redeem' => true, 'is_contract' => false, 'is_invoice' => true,
                'counts_for_rank' => false, 'rank_factor' => 0,
                'validity_months' => 0, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 0,
                'ic_schedule' => ['5.0', '1.50', '0.50', '0.50', '0.50', '0.50', '0.40', '0.40', '0.40', '0.40'],
                'level_schedule' => $zeroLevel,
                'level_com_duration' => 0, 'billing_margin' => 0, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => null, 'is_active' => true, 'useraccess' => false,
                'settlement' => null],

            ['code' => 'P209', 'hid' => null, 'name' => ['en' => 'G11 Gold Savings Plan PLUS1'],
                'plan_type' => 1, 'type' => 'rd', 'min_value' => 1500,
                'allocation_bv' => 0, 'allocation_cont' => 100,
                'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
                'counts_for_rank' => false, 'rank_factor' => 0,
                'validity_months' => 11, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 0,
                'ic_schedule' => ['5.0', '1.5', '1.0', '0.75', '0.375', '0.375', '0.25', '0.25', '0.25', '0.25'],
                'level_schedule' => $zeroLevel,
                'level_com_duration' => 0, 'billing_margin' => 5, 'renewal_margin' => 5,
                'epin_count' => 1, 'mou_id' => 1, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_bonus_months' => 1, 'settlement_close' => true,
                'rd_qr_grams' => 0.100, 'rd_qr_on' => 'always',   // 100 mg gold QR at bill + every renewal
                'settlement' => '12 month 11m+ 1 month commission gold qr'],

            ['code' => 'P208', 'hid' => null, 'name' => ['en' => 'G11 Gold Savings Plan PLUS2'],
                'plan_type' => 1, 'type' => 'rd', 'min_value' => 1500,
                'allocation_bv' => 100, 'allocation_cont' => 100,
                'is_redeem' => true, 'is_contract' => true, 'is_invoice' => true,
                'counts_for_rank' => true, 'rank_factor' => 100,
                'validity_months' => 11, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 0,
                'ic_schedule' => $plusIc, 'level_schedule' => $zeroLevel,
                'level_com_duration' => 0, 'billing_margin' => 10, 'renewal_margin' => 10,
                'epin_count' => 1, 'mou_id' => 1, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_bonus_months' => 2, 'settlement_close' => true,
                'rd_qr_grams' => 0.100, 'rd_qr_on' => 'first_renewal',   // single 100 mg QR at first renewal
                'settlement' => '12 month 11m+ 2 month commission gold qr'],

            ['code' => 'P200', 'hid' => null, 'name' => ['en' => 'G11 Gold Savings Plan PLUS3'],
                'plan_type' => 1, 'type' => 'rd', 'min_value' => 1000,
                'allocation_bv' => 100, 'allocation_cont' => 100,
                'is_redeem' => false, 'is_contract' => true, 'is_invoice' => false,
                'counts_for_rank' => true, 'rank_factor' => 100,
                'validity_months' => 11, 'cbc_value' => 0, 'cbc_count' => 0, 'level_depth' => 0,
                'ic_schedule' => $plusIc, 'level_schedule' => $zeroLevel,
                'level_com_duration' => 0, 'billing_margin' => 10, 'renewal_margin' => 10,
                'epin_count' => 1, 'mou_id' => 1, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_bonus_months' => 3, 'settlement_close' => true,
                'settlement' => '12 th month closing with 11m+ 3 m commission gold qr'],

            ['code' => 'P212', 'hid' => null, 'name' => ['en' => 'G36 Smart Gold Booking Plan'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 100000, 'max_sales_value' => 5000000,
                'allocation_bv' => 100, 'allocation_cont' => 100,
                'is_redeem' => false, 'is_contract' => true, 'is_invoice' => false,
                'counts_for_rank' => true, 'rank_factor' => 100,
                'validity_months' => 36, 'cbc_value' => 6, 'cbc_count' => 36, 'level_depth' => 5,
                'ic_schedule' => $g5Ic, 'level_schedule' => $g5Level,
                'level_com_duration' => 36, 'billing_margin' => 1, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 2, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 36,
                'settlement' => '36 month after closing/renewal'],

            ['code' => 'P202', 'hid' => null, 'name' => ['en' => 'G12 Forward contractors'],
                'plan_type' => 2, 'type' => 'digital', 'min_value' => 10000,
                'allocation_bv' => 100, 'allocation_cont' => 100,
                'is_redeem' => false, 'is_contract' => true, 'is_invoice' => false,
                'counts_for_rank' => true, 'rank_factor' => 100,
                'validity_months' => 12, 'cbc_value' => 15, 'cbc_count' => 12, 'level_depth' => 5,
                'ic_schedule' => ['7.50', '2.25', '0.75', '0.75', '0.75', '0.60', '0.60', '0.60', '0.60', '0.60'],
                'level_schedule' => ['2.5', '1.00', '0.50', '0.50', '0.50'],
                'level_com_duration' => 12, 'billing_margin' => 2, 'renewal_margin' => 0,
                'epin_count' => 1, 'mou_id' => 3, 'is_active' => true, 'useraccess' => false,
                'settlement_cycle_months' => 12, 'settlement_qr_pct' => 100, 'settlement_close' => true, 'settlement_suspend' => true,
                'settlement' => '12 month closing with invoice amount gold qr'],
        ];
    }
}
