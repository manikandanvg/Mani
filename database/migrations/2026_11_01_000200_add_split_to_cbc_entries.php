<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-01: a CBC coupon is split at approval — the sales-profit share to the CASH
 * wallet, TDS + service charge withheld on the whole coupon, the remainder to the (HQ-only)
 * coupon wallet. The split is stored on the coupon for the statement / audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbc_entries', function (Blueprint $table) {
            $table->decimal('cash_amount', 15, 2)->default(0)->after('worth');
            $table->decimal('tds', 15, 2)->default(0)->after('cash_amount');
            $table->decimal('service_charge', 15, 2)->default(0)->after('tds');
            $table->decimal('coupon_amount', 15, 2)->default(0)->after('service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('cbc_entries', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'tds', 'service_charge', 'coupon_amount']);
        });
    }
};
