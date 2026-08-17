<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile app revert-2 batch (board 2026-08-12):
 *  - Re-KYC window on kyc_settings (item 17a): admin turns it on with a date
 *    window; the app then forces PAN (digital) + Aadhaar (upload → manual
 *    approval) before continuing.
 *  - members.aadhaar_doc_path (item 18): the uploaded Aadhaar card photo the
 *    admin reviews before flipping aadhaar_verified.
 *  - mobile_devices (items 16/17b): registry of app installs — phone number,
 *    distributor, app-generated device uid, biometric enrollment flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_settings', function (Blueprint $table) {
            $table->boolean('rekyc_enabled')->default(false)->after('aadhaar_otp_enabled');
            $table->date('rekyc_from')->nullable()->after('rekyc_enabled');
            $table->date('rekyc_until')->nullable()->after('rekyc_from');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('aadhaar_doc_path')->nullable()->after('aadhaar_verified_at');
        });

        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('phone', 20)->nullable();          // login phone snapshot
            $table->string('device_uid', 64)->unique();       // app-generated, stable per install
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 12)->default('android');
            $table->boolean('biometric_enabled')->default(false);  // fingerprint/face unlock on
            $table->string('app_version', 24)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('aadhaar_doc_path'));
        Schema::table('kyc_settings', fn (Blueprint $table) => $table->dropColumn(['rekyc_enabled', 'rekyc_from', 'rekyc_until']));
    }
};
