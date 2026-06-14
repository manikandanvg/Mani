<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live metal rates are per-region: London gold is quoted natively in its own currency
 * (it is NOT the Indian rate × FX — duties, VAT and premiums differ). Each rate row now
 * records which currency its gold/silver/diamond per-gram prices are stated in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_rates', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('INR')->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('live_rates', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
