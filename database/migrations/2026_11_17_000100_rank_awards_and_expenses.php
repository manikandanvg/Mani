<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-17 (points 4 + 5).
 *
 *  ranks     — the AWARD of a TBP stage in words ("Car award", "International tour" …; the old
 *              system kept this as tbl_levels.pricing) and the MONTHLY OFFICE RENT the stage
 *              is entitled to. reward_amount stays the rupee value of that award.
 *  expenses  — the expense register: every rupee Head Office spends outside the system's own
 *              payouts (awards given, office rent, salaries outside payroll, electricity …),
 *              submitted with its bill, approved by the owner, and read by the auditor report.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ranks', function (Blueprint $table) {
            if (! Schema::hasColumn('ranks', 'reward_title')) {
                $table->json('reward_title')->nullable()->after('target_bv');   // translatable {en:.., ta:..}
            }
            if (! Schema::hasColumn('ranks', 'monthly_rent')) {
                $table->decimal('monthly_rent', 12, 2)->default(0)->after('reward_amount');
            }
        });

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('expense_no', 30)->unique();
                $table->date('expense_date')->index();
                $table->string('category', 40)->index();           // App\Models\Expense::CATEGORIES key
                $table->string('title', 200);                      // what was paid for
                $table->decimal('amount', 15, 2);
                $table->string('payment_mode', 20)->default('bank');
                $table->string('payment_reference', 100)->nullable();   // UTR / cheque no
                $table->string('payee_name', 200)->nullable();     // who received the money
                $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();   // distributor awarded / whose office
                $table->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();       // TBP stage the award belongs to
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();  // branch / office concerned
                $table->string('period_month', 7)->nullable();     // YYYY-MM a monthly expense (rent) is for
                $table->json('attachments')->nullable();           // bills / receipts (private disk)
                $table->text('remarks')->nullable();
                $table->string('status', 20)->default('submitted')->index();   // submitted | approved | rejected
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('reject_reason', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::table('ranks', function (Blueprint $table) {
            foreach (['reward_title', 'monthly_rent'] as $col) {
                if (Schema::hasColumn('ranks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
