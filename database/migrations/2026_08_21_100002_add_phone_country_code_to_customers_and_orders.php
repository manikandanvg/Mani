<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board phase-1 (2026-08-21): the storefront now stores the dialling code
 * separately from the number, matching members.phone_country_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_country_code', 8)->default('+91')->after('phone');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('phone_country_code', 8)->default('+91')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('phone_country_code'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('phone_country_code'));
    }
};
