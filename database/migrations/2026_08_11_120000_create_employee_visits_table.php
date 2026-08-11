<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Board 2026-08-11: an employee's RFID tap at a box that is NOT their home branch
// (another dealer branch, or the branch-less MEETING-ARENA box) records a VISIT —
// separate from the one-per-day payroll attendance row. HQ monitors visits per
// employee and check-ins per device.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();  // null = meeting arena
            $table->timestamp('visited_at');
            $table->timestamps();
            $table->index(['employee_profile_id', 'visited_at']);
            $table->index(['device_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_visits');
    }
};
