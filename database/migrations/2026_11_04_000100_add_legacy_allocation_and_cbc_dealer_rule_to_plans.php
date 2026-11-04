<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-04 ("bill the legacy way"):
 *
 *  - plans.allocation  = the legacy tbl_planjewel.allocation — the share of a bill that becomes
 *    the bond (legacy bvalue): 80 % on the dealerships, 70 % G24, 50 % G5, 25 % Sub Dealer,
 *    10 % Area Distributor, 100 % everywhere else. The billing code had taken allocation_bv
 *    (20 % on a dealership) as the bond and computed every commission on it.
 *  - plans.cbc_dealer_rule = the legacy bank report's dealership exception (planid 203/204/208 =
 *    Taluka/District/Zonal, plus the new Regional): the coupon is paid 100 % to cash with 10 % TDS
 *    and no service charge, nothing to the HQ coupon wallet.
 */
return new class extends Migration
{
    /** English name => legacy allocation % */
    private const ALLOCATION = [
        'regional dealership' => 80, 'zonal dealership' => 80, 'district dealership' => 80, 'taluka dealership' => 80,
        'g24 wholesaler plan' => 70, 'g5 retailer plan' => 50, 'sub dealer' => 25, 'area distributor' => 10,
        'g12 forward contractors' => 100, 'g36 smart gold booking plan' => 100,
        'g11 gold savings plan plus1' => 100, 'g11 gold savings plan plus2' => 100, 'g11 gold savings plan plus3' => 100,
        'g12 smart gold savings plan' => 100, 'g10 gold purchase plan' => 100, 'g10 silver purchase plan' => 100,
    ];

    private const DEALER_RULE = ['regional dealership', 'zonal dealership', 'district dealership', 'taluka dealership'];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'allocation')) {
                $table->decimal('allocation', 6, 3)->nullable()->after('allocation_cont')
                    ->comment('Legacy allocation: % of the bill that becomes the bond (bvalue)');
            }
            if (! Schema::hasColumn('plans', 'cbc_dealer_rule')) {
                $table->boolean('cbc_dealer_rule')->default(false)->after('cbc_count')
                    ->comment('CBC paid 100% cash, TDS only (legacy dealership plans)');
            }
        });

        foreach (DB::table('plans')->get(['id', 'name', 'allocation_bv', 'allocation_cont', 'code']) as $p) {
            $name = json_decode((string) $p->name, true);
            $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) (is_array($name) ? ($name['en'] ?? reset($name)) : $name))));
            $key = preg_replace('/ \(legacy\)$/', '', $key);
            $alloc = self::ALLOCATION[$key] ?? null;
            if ($alloc === null) {
                // legacy-only L### plans were imported with allocation_bv = legacy allocation
                $alloc = str_starts_with((string) $p->code, 'L') && (float) $p->allocation_bv > 0
                    ? (float) $p->allocation_bv
                    : ((float) $p->allocation_cont > 0 ? (float) $p->allocation_cont : 100.0);
            }
            DB::table('plans')->where('id', $p->id)->update([
                'allocation' => $alloc,
                'cbc_dealer_rule' => in_array($key, self::DEALER_RULE, true),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['allocation', 'cbc_dealer_rule']);
        });
    }
};
