<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-01 (night): the legacy digi-gold opening balance is NOT pushed into the
 * distributor's purse by the import. It waits as a digi_orders OPENING row on
 * Commissions → Pending Digi until Head Office sends it to the purse (one line in
 * digi_gold_txns + member_wallets.digi_gold_grams), per row or in bulk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digi_orders', function (Blueprint $table) {
            $table->string('status', 16)->default('credited')->after('branch_id')->index();
            $table->unsignedBigInteger('decided_by')->nullable()->after('status');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('digi_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'decided_by', 'decided_at']);
        });
    }
};
