<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payroll conversion (2026-07 board/auditor mandate): ranked distributors are also
 * employees of the company. TBP stages (ranks) carry a monthly salary + TDS %, each
 * enrolled distributor gets an employee_profile, daily attendance is captured (app
 * selfie + GPS, or manual/HQ), and a monthly payroll run turns attendance into
 * payslips with statutory PF / ESI / TDS deductions.
 */
return new class extends Migration {
    public function up(): void
    {
        // Salary + TDS defaults live on the TBP stage (rank); the profile can override.
        Schema::table('ranks', function (Blueprint $table) {
            $table->decimal('monthly_salary', 12, 2)->default(0)->after('reward_amount');
            $table->decimal('tds_pct', 5, 2)->default(0)->after('monthly_salary');
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_code', 40)->unique();
            $table->string('designation', 120)->nullable();       // from the TBP stage at enrolment
            $table->foreignId('rank_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('monthly_salary', 12, 2)->default(0);  // copied from rank, overridable
            $table->decimal('basic_pct', 5, 2)->default(50);       // basic = % of gross (PF base)
            $table->boolean('pf_enabled')->default(true);
            $table->string('uan', 20)->nullable();                 // EPF UAN
            $table->boolean('esi_enabled')->default(true);
            $table->string('esic_number', 20)->nullable();
            $table->decimal('tds_pct', 5, 2)->nullable();          // null → rank default
            $table->date('date_of_joining');
            $table->string('status', 16)->default('active');       // active | suspended | exited
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('selfie_path')->nullable();             // front-camera capture at check-in
            $table->string('checkout_selfie_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('checkout_lat', 10, 7)->nullable();
            $table->decimal('checkout_lng', 10, 7)->nullable();
            $table->string('source', 16)->default('app');          // app | manual | device (L-BOX later)
            $table->string('status', 16)->default('present');      // present | half_day | absent | leave
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_profile_id', 'date']);
            $table->index('date');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedTinyInteger('working_days');           // denominator for proration
            $table->string('status', 16)->default('draft');        // draft | approved | paid
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('deduction_total', 14, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('days_present', 5, 1)->default(0);     // half day = 0.5
            $table->decimal('payable_days', 5, 1)->default(0);
            $table->decimal('monthly_salary', 12, 2)->default(0);  // frozen at generation
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('pf_employee', 10, 2)->default(0);
            $table->decimal('pf_employer', 10, 2)->default(0);
            $table->decimal('esi_employee', 10, 2)->default(0);
            $table->decimal('esi_employer', 10, 2)->default(0);
            $table->decimal('tds', 10, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->string('status', 16)->default('pending');      // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->json('snapshot')->nullable();                  // rates/ceilings used
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employee_profiles');
        Schema::table('ranks', function (Blueprint $table) {
            $table->dropColumn(['monthly_salary', 'tds_pct']);
        });
    }
};
