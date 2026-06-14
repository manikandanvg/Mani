<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sales income side-tables: the monthly CBC (cashback) schedule and the branch
// bill-margin ledger (legacy tbl_cbc / tbl_reseller_com). IC + GAP earnings live in
// commission_ledger; these two are distinct streams.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cbc_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bond_id')->constrained('bonds')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->date('cbc_date');                 // month this installment matures
            $table->string('code', 16)->unique();
            $table->decimal('worth', 15, 2);
            $table->enum('status', ['pending', 'paid', 'used'])->default('pending');
            $table->string('used_for', 60)->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'status']);
        });

        Schema::create('reseller_commissions', function (Blueprint $table) {
            $table->id();
            $table->date('bill_date');
            $table->string('invoice_no', 40)->nullable();
            $table->unsignedTinyInteger('com_type_id')->default(1); // 1 = billing margin
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // billed-by staff
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('mapped_uid', 40)->nullable();
            $table->decimal('com_value', 15, 2);
            $table->foreignId('reference_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->enum('status', ['pending', 'passed', 'paid'])->default('passed');
            $table->timestamps();
            $table->index(['branch_id', 'bill_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_commissions');
        Schema::dropIfExists('cbc_entries');
    }
};
