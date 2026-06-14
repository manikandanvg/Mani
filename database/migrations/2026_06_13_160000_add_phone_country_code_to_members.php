<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing now serves a wider range of customers, so the phone is stored alongside its
 * dialling country code. Defaults to India (+91) — existing rows are domestic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('phone_country_code', 8)->default('+91')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('phone_country_code');
        });
    }
};
