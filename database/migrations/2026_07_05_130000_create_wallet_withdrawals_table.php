<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Closed-wallet withdrawals via the branch L-BOX static QR. All 7 earning streams
 * credit ONE member wallet (cash_balance, at commission approval); the member scans
 * the box's QR, the balance moves to the branch, the box announces it, and the
 * branch incharge hands over gold/money and marks the row disbursed. NO real-money
 * gateway is involved anywhere in this flow.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('pending');   // pending | disbursed | cancelled
            $table->string('disbursal_mode', 12)->nullable();   // cash | gold (what the incharge handed over)
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
    }
};
