<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 2026-11-02: the legacy accrued billing margin of every dealer (ci_users.billmargin, the
 * UNPAID balance the dealer saw in legacy) is not pushed into any wallet. It waits here, one
 * row per branch, and Head Office sends it to the dealer's cash wallet by hand on
 * Commissions → Pending Billing Margin — exactly like Pending Cash QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_bill_margins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('source', 20)->default('legacy');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending');          // pending | sent | rejected
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();   // wallet credited
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'source']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_bill_margins');
    }
};
