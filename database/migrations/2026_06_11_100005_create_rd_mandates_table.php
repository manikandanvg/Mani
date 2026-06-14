<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RD / Gold-Saving auto-debit (e-mandate) registrations. An opt-in layer on top of the
 * manual RD collection: a Razorpay Subscription per RD bond. Razorpay drives the monthly
 * schedule; each successful charge is recorded against the bond via the EXISTING manual
 * RdCollectionService — the manual flow itself is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rd_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bond_id')->constrained('bonds')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('razorpay_plan_id', 64)->nullable();
            $table->string('razorpay_subscription_id', 64)->nullable()->unique();
            $table->decimal('amount', 15, 2)->default(0);     // per-installment amount (INR)
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('paid_count')->default(0);
            // mirrors Razorpay subscription states
            $table->string('status', 20)->default('created'); // created|authenticated|active|pending|halted|completed|cancelled
            $table->date('next_charge_on')->nullable();
            $table->string('short_url', 255)->nullable();      // customer authorisation link
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['bond_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rd_mandates');
    }
};
