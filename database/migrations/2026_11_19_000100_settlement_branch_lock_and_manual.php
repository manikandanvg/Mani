<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlements page, user 2026-11-19:
 *  - redeemable_qrs.only_branch_id
 *        a settlement QR is redeemed only at the contract's own branch (Head Office excepted).
 *  - rd_settlements.mode
 *        qr | manual — Head Office may settle by hand (payout outside the system) and issue no QR.
 *        contract_settlements.mode already exists and takes the new value 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('redeemable_qrs', 'only_branch_id')) {
            Schema::table('redeemable_qrs', function (Blueprint $t) {
                $t->unsignedBigInteger('only_branch_id')->nullable()->after('branch_id');
            });
        }
        if (! Schema::hasColumn('rd_settlements', 'mode')) {
            Schema::table('rd_settlements', function (Blueprint $t) {
                $t->string('mode', 12)->default('qr')->after('metal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('redeemable_qrs', 'only_branch_id')) {
            Schema::table('redeemable_qrs', fn (Blueprint $t) => $t->dropColumn('only_branch_id'));
        }
        if (Schema::hasColumn('rd_settlements', 'mode')) {
            Schema::table('rd_settlements', fn (Blueprint $t) => $t->dropColumn('mode'));
        }
    }
};
