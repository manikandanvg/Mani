<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leave requests (payroll mandate follow-up): an employee asks for a date range off
 * from the app; HQ approves it as PAID or UNPAID leave (or rejects). Approval writes
 * the attendance_records rows the payroll run reads — the request itself never pays.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('pending');      // pending | approved | rejected
            $table->string('approved_type', 16)->nullable();       // paid_leave | leave (set at approval)
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('admin_note')->nullable();
            $table->timestamps();

            $table->index(['employee_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
