<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row KYC verification settings the admin can toggle (System → Verification
 * Settings). aadhaar_otp_enabled switches Aadhaar from instant offline checksum to
 * strong UIDAI OTP e-KYC via the Sandbox gateway.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kyc_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('aadhaar_otp_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_settings');
    }
};
