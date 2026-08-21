<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board phase-1 (2026-08-21): the billing address gains State / District / Taluka,
 * auto-filled from the pincode master when the operator types a PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('taluka', 120)->nullable()->after('city');
            $table->string('district', 120)->nullable()->after('taluka');
            $table->string('state', 120)->nullable()->after('district');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['taluka', 'district', 'state']);
        });
    }
};
