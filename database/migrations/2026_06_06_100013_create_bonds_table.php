<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A purchased plan instance — the unit that generates commissions over time.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('bonds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->date('bond_date');
            $table->decimal('value', 15, 2);
            $table->string('invoice_no', 40)->nullable();
            $table->json('lvlcom')->nullable();                 // [l1..l5] captured amounts
            $table->decimal('cbc_value', 15, 2)->default(0);
            $table->unsignedInteger('cbc_count')->default(0);
            $table->unsignedInteger('cbc_issued')->default(0);
            $table->unsignedInteger('lvlcom_count')->default(0);
            $table->unsignedInteger('lvlcom_issued')->default(0);
            $table->decimal('epin_value', 15, 2)->default(0);
            $table->date('return_date')->nullable();
            $table->foreignId('mou_id')->nullable()->constrained('mous')->nullOnDelete();
            $table->enum('status', ['active', 'closed', 'cancelled'])->default('active');
            $table->timestamps();
            $table->index('status');
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonds');
    }
};
