<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distributor margins (billing/gold/silver/stock-transfer) are now released to the
 * member wallet only on admin approval — record the approval date alongside status='paid'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_commissions', function (Blueprint $table) {
            $table->date('paid_on')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_commissions', function (Blueprint $table) {
            $table->dropColumn('paid_on');
        });
    }
};
