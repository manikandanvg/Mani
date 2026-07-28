<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured settlement config (board settlement.csv deep notes, 2026-07-28) — the
 * free-text plans.settlement note becomes executable:
 *
 *   settlement_cycle_months  months from contract start to THE settlement event (null = never)
 *   settlement_qr_pct        settlement gold-QR worth as % of the contract amount
 *   settlement_bonus_months  RD plans: bonus months added to the paid total for the QR worth
 *   settlement_close         close the contract + bond at settlement
 *   settlement_suspend       suspend the member's dealer login at settlement
 *
 * No pct/bonus/close ⇒ the contract just goes 'matured' for the manual withdraw/renewal
 * decision (dealerships check opening stock by hand). member_contracts.settled_on marks
 * the one-shot event so the engine never settles twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('settlement_cycle_months')->nullable()->after('settlement');
            $table->decimal('settlement_qr_pct', 6, 2)->nullable()->after('settlement_cycle_months');
            $table->unsignedTinyInteger('settlement_bonus_months')->default(0)->after('settlement_qr_pct');
            $table->boolean('settlement_close')->default(false)->after('settlement_bonus_months');
            $table->boolean('settlement_suspend')->default(false)->after('settlement_close');
        });

        Schema::table('member_contracts', function (Blueprint $table) {
            $table->date('settled_on')->nullable()->after('status');
        });

        // Backfill per the settlement sheet (also carried by PlanSchemaV2Seeder).
        $rows = [
            // dealerships: 24-month cycle, manual withdraw/renewal decision (matured)
            'P214' => ['settlement_cycle_months' => 24],
            'P207' => ['settlement_cycle_months' => 24],
            'P204' => ['settlement_cycle_months' => 24],
            'P203' => ['settlement_cycle_months' => 24],
            // 12-month settlement QR from the contract amount, contract runs on
            'P213' => ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 80],
            'P201' => ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 70],
            'P205' => ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 80],
            // 12-month auto-close (+ dealer-login suspension)
            'P210' => ['settlement_cycle_months' => 12, 'settlement_close' => true, 'settlement_suspend' => true],
            'P202' => ['settlement_cycle_months' => 12, 'settlement_qr_pct' => 100, 'settlement_close' => true, 'settlement_suspend' => true],
            // G11 RD: paid total + bonus months as gold QR, then close
            'P209' => ['settlement_cycle_months' => 12, 'settlement_bonus_months' => 1, 'settlement_close' => true],
            'P208' => ['settlement_cycle_months' => 12, 'settlement_bonus_months' => 2, 'settlement_close' => true],
            'P200' => ['settlement_cycle_months' => 12, 'settlement_bonus_months' => 3, 'settlement_close' => true],
            // G36: 36-month maturity, customer chooses renew/withdraw
            'P212' => ['settlement_cycle_months' => 36],
            // P206 / P211 runtime bills: no settlement (columns stay null/0)
        ];
        foreach ($rows as $code => $attrs) {
            DB::table('plans')->where('code', $code)->update($attrs);
        }
    }

    public function down(): void
    {
        Schema::table('member_contracts', function (Blueprint $table) {
            $table->dropColumn('settled_on');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'settlement_cycle_months', 'settlement_qr_pct', 'settlement_bonus_months',
                'settlement_close', 'settlement_suspend',
            ]);
        });
    }
};
