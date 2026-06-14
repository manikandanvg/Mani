<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-sale contract record (legacy "CONTRACT" / tbl_membermou equivalent). Generated
 * when a bond is created: a random contract number + the per-plan content snapshot
 * (savings/dealership/income breakup, mirroring Lscript::getforcont). The contract PDF
 * renders from this row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no', 32)->unique();
            $table->foreignId('bond_id')->nullable()->constrained('bonds')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('invoice_no', 40)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->longText('content')->nullable();   // rendered per-plan HTML snapshot
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('invoice_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_contracts');
    }
};
