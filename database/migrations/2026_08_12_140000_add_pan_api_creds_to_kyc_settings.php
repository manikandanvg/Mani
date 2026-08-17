<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sandbox (sandbox.co.in) API credentials move under admin control (user request
 * 2026-08-12): System → Verification Settings stores the live key/secret DB-first,
 * .env stays as the fallback — same convention as Push Notification Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_settings', function (Blueprint $table) {
            $table->string('pan_driver', 12)->nullable()->after('aadhaar_otp_enabled');   // null = use .env
            $table->string('sandbox_key')->nullable()->after('pan_driver');
            $table->text('sandbox_secret')->nullable()->after('sandbox_key');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_settings', fn (Blueprint $table) => $table->dropColumn(['pan_driver', 'sandbox_key', 'sandbox_secret']));
    }
};
