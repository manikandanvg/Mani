<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist KYC verification outcomes on the member so a verified PAN/Aadhaar shows up
 * again when the customer is re-selected (no re-verification needed).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('pan_verified')->default(false)->after('pan');
            $table->string('pan_verified_name', 200)->nullable()->after('pan_verified');
            $table->timestamp('pan_verified_at')->nullable()->after('pan_verified_name');
            $table->boolean('aadhaar_verified')->default(false)->after('aadhaar');
            $table->timestamp('aadhaar_verified_at')->nullable()->after('aadhaar_verified');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['pan_verified', 'pan_verified_name', 'pan_verified_at', 'aadhaar_verified', 'aadhaar_verified_at']);
        });
    }
};
