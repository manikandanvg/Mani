<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board / user 2026-11-25: Head Office decides, per dealership scheme, whether the branches of
 * that scheme may access the Settlement — a switch on the scheme.
 *
 *   on   the branch does settlements: it is the settling branch of the contracts it billed,
 *        redeems their settlement QR and sees the Settlements page
 *   off  it takes no part: no Settlements page, it cannot redeem a settlement QR, and a contract
 *        it billed is settled by the dealer above it (else Head Office)
 *
 * Starts as the board ruled: OFF for Reseller, Sub Dealer and Area Distributor (plans.hid 5, 6, 8),
 * on for every other scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'settlement_enabled')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->boolean('settlement_enabled')->default(true);
            });
            DB::table('plans')->whereIn('hid', [5, 6, 8])->update(['settlement_enabled' => false]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'settlement_enabled')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('settlement_enabled');
            });
        }
    }
};
