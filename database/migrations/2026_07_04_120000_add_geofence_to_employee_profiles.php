<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Optional geofenced check-in: when a radius is set AND the employee's branch has a
 * map pin, app check-ins beyond the radius are rejected. Null = no restriction
 * (field distributors roam; showroom staff can be pinned to the branch).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->unsignedInteger('geofence_radius_m')->nullable()->after('tds_pct');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('geofence_radius_m');
        });
    }
};
